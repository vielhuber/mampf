<?php
declare(strict_types=1);

namespace Mampf;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDOException;
use RuntimeException;

final class Application
{
    public function __construct(private readonly Runtime $runtime) {}

    public function run(): never
    {
        $path = (string) parse_url(url: (string) ($_SERVER['REQUEST_URI'] ?? '/'), component: PHP_URL_PATH);
        if ($path === '/cron') {
            $this->handleCron();
        }
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 365,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
        if (!$this->runtime->auth->isLoggedIn()) {
            $this->renderLogin();
        }
        if ($path === '/task/cancel') {
            $this->handleTaskCancellation();
        }
        if ($path === '/task') {
            $this->handleTask();
        }
        if ($path === '/feedback') {
            $this->handleFeedback();
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleAction();
        }

        $now = new DateTimeImmutable(datetime: 'now');
        $year = max(2020, min(2100, (int) ($_GET['year'] ?? $now->format(format: 'o'))));
        $week = max(1, min(53, (int) ($_GET['week'] ?? $now->format(format: 'W'))));
        $search = trim(string: (string) ($_GET['search'] ?? ''));
        $ingredientFilterValue = (string) ($_GET['ingredients'] ?? 'mapped');
        $ingredientFilter = match ($ingredientFilterValue) {
            'all', 'unmapped' => $ingredientFilterValue,
            default => 'mapped'
        };
        $weekFilterValue = (string) ($_GET['week_filter'] ?? 'all');
        $weekFilter = match ($weekFilterValue) {
            'selected', 'unselected' => $weekFilterValue,
            default => 'all'
        };
        $category = trim(string: (string) ($_GET['category'] ?? ''));
        $sortValue = (string) ($_GET['sort'] ?? 'favorites_desc');
        $sort = match ($sortValue) {
            'favorites_desc', 'ratings_desc', 'rating_desc', 'name_asc', 'name_desc', 'created_desc' => $sortValue,
            default => 'favorites_desc'
        };
        $partial = (string) ($_GET['partial'] ?? '') === '1';
        $page = $partial ? max(1, (int) ($_GET['page'] ?? 1)) : 1;
        $perPage = 24;
        $recipes = $this->runtime->database->recipes(
            search: $search,
            page: $page,
            perPage: $perPage,
            year: $year,
            week: $week,
            ingredientFilter: $ingredientFilter,
            weekFilter: $weekFilter,
            category: $category,
            sort: $sort,
            userId: $this->currentUser()->id
        );
        $count = $this->runtime->database->recipeCount(
            search: $search,
            year: $year,
            week: $week,
            ingredientFilter: $ingredientFilter,
            weekFilter: $weekFilter,
            category: $category
        );
        $this->renderDashboard(
            recipes: $recipes,
            total: $count,
            weekRecipeCount: $this->runtime->database->weekRecipeCount(year: $year, week: $week),
            year: $year,
            week: $week,
            search: $search,
            ingredientFilter: $ingredientFilter,
            weekFilter: $weekFilter,
            category: $category,
            sort: $sort,
            page: $page,
            pages: max(1, (int) ceil(num: $count / $perPage)),
            partial: $partial
        );
    }

    private function handleCron(): never
    {
        header(header: 'Content-Type: application/json; charset=utf-8');
        header(header: 'Cache-Control: no-store');
        if (!in_array(needle: (string) ($_SERVER['REQUEST_METHOD'] ?? ''), haystack: ['GET', 'POST'], strict: true)) {
            header(header: 'Allow: GET, POST');
            http_response_code(response_code: 405);
            echo json_encode(value: ['error' => 'Methode nicht erlaubt.'], flags: JSON_THROW_ON_ERROR);
            exit();
        }
        $configuredToken = trim(string: (string) ($_SERVER['CRON_TOKEN'] ?? ''));
        if ($configuredToken === '') {
            http_response_code(response_code: 503);
            echo json_encode(value: ['error' => 'CRON_TOKEN ist nicht konfiguriert.'], flags: JSON_THROW_ON_ERROR);
            exit();
        }
        $providedToken = trim(string: (string) ($_GET['token'] ?? ''));
        if ($providedToken === '' || !hash_equals(known_string: $configuredToken, user_string: $providedToken)) {
            http_response_code(response_code: 401);
            echo json_encode(value: ['error' => 'Ungültiges Cron-Token.'], flags: JSON_THROW_ON_ERROR);
            exit();
        }

        $dataDirectory = $this->runtime->root . '/.data';
        if (
            !is_dir(filename: $dataDirectory) &&
            !mkdir(directory: $dataDirectory, permissions: 0770, recursive: true)
        ) {
            http_response_code(response_code: 500);
            echo json_encode(
                value: ['error' => 'Das Datenverzeichnis konnte nicht erstellt werden.'],
                flags: JSON_THROW_ON_ERROR
            );
            exit();
        }
        $lockHandle = fopen(filename: $dataDirectory . '/cron.lock', mode: 'c+');
        if ($lockHandle === false) {
            http_response_code(response_code: 500);
            echo json_encode(
                value: ['error' => 'Die Cron-Sperre konnte nicht geöffnet werden.'],
                flags: JSON_THROW_ON_ERROR
            );
            exit();
        }
        if (!flock(stream: $lockHandle, operation: LOCK_EX | LOCK_NB)) {
            fclose(stream: $lockHandle);
            http_response_code(response_code: 409);
            echo json_encode(value: ['error' => 'Die Cron-Aktualisierung läuft bereits.'], flags: JSON_THROW_ON_ERROR);
            exit();
        }

        set_time_limit(seconds: 0);
        ignore_user_abort(enable: true);
        $logFile = $dataDirectory . '/cron.log';
        $appendLog = static function (string $level, string $message) use ($logFile): void {
            $line = sprintf("[%s] %-5s %s\n", date(format: 'Y-m-d H:i:s'), $level, $message);
            if (file_put_contents(filename: $logFile, data: $line, flags: FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException(message: 'Das Cron-Protokoll konnte nicht geschrieben werden.');
            }
        };
        $startedAt = microtime(as_float: true);
        $statusCode = 200;
        $response = [];
        $activeSyncType = null;
        try {
            $appendLog('START', 'Aktualisierung gestartet.');
            $appendLog('INFO', 'HelloFresh-Rezepte werden aktualisiert.');
            $activeSyncType = Database::SYNC_RECIPES;
            $recipes = $this->runtime->helloFreshScraper->scrapeRecipes(
                progress: static function (int $scanned, int $total, int $created, int $updated) use (
                    $appendLog
                ): void {
                    $appendLog(
                        'INFO',
                        sprintf(
                            'Rezepte: %d/%d geprüft, %d neu, %d aktualisiert.',
                            $scanned,
                            $total,
                            $created,
                            $updated
                        )
                    );
                }
            );
            $recipeSyncStatus =
                $recipes['unresolved'] === 0 ? Database::SYNC_STATUS_SUCCESS : Database::SYNC_STATUS_ERROR;
            $recipeSyncMessage = sprintf(
                '%d neue, %d aktualisierte, %d ausgefilterte und %d nicht aufgelöste Rezepte.',
                $recipes['created'],
                $recipes['updated'],
                $recipes['filtered'],
                $recipes['unresolved']
            );
            $this->runtime->database->recordSyncRun(
                type: Database::SYNC_RECIPES,
                status: $recipeSyncStatus,
                message: $recipeSyncMessage
            );
            $activeSyncType = null;
            $appendLog(
                'INFO',
                sprintf(
                    'Rezepte abgeschlossen: %d neu, %d aktualisiert, %d ausgefiltert, %d nicht aufgelöst.',
                    $recipes['created'],
                    $recipes['updated'],
                    $recipes['filtered'],
                    $recipes['unresolved']
                )
            );
            $appendLog('INFO', 'REWE-Zutaten werden zugeordnet.');
            $activeSyncType = Database::SYNC_INGREDIENTS;
            $ingredients = $this->runtime->helloFreshScraper->scrapeIngredients(
                reweClient: $this->runtime->reweClient,
                progress: static function (
                    string $name,
                    bool $success,
                    int $current,
                    int $total,
                    string $error = ''
                ) use ($appendLog): void {
                    if ($current % 100 !== 0 && $current !== $total && !$success) {
                        $appendLog('ERROR', $name . ': ' . $error);
                        return;
                    }
                    if ($current % 100 !== 0 && $current !== $total) {
                        return;
                    }
                    $appendLog('INFO', sprintf('Zutaten: %d/%d Rezepte verarbeitet.', $current, $total));
                }
            );
            $ingredientSyncStatus =
                $ingredients['failed'] === 0 ? Database::SYNC_STATUS_SUCCESS : Database::SYNC_STATUS_ERROR;
            $this->runtime->database->recordSyncRun(
                type: Database::SYNC_INGREDIENTS,
                status: $ingredientSyncStatus,
                message: $this->ingredientSyncMessage(result: $ingredients)
            );
            $activeSyncType = null;
            if ($ingredients['errors'] !== []) {
                foreach ($ingredients['errors'] as $error) {
                    $appendLog('ERROR', $error);
                }
            }
            $appendLog(
                $ingredients['failed'] === 0 ? 'INFO' : 'ERROR',
                sprintf(
                    'Zutaten abgeschlossen: %d Rezepte verarbeitet, %d fehlgeschlagen.',
                    $ingredients['processed'],
                    $ingredients['failed']
                )
            );
            $duration = round(num: microtime(as_float: true) - $startedAt, precision: 2);
            $appendLog($ingredients['failed'] === 0 ? 'DONE' : 'ERROR', 'Laufzeit: ' . $duration . ' Sekunden.');
            $statusCode = $ingredients['failed'] === 0 ? 200 : 500;
            $response = [
                'status' => $ingredients['failed'] === 0 ? 'success' : 'partial',
                'recipes' => $recipes,
                'ingredients' => $ingredients,
                'duration_seconds' => $duration,
                'log' => '.data/cron.log'
            ];
        } catch (RuntimeException | JsonException | PDOException $exception) {
            $statusCode = 500;
            $response = ['status' => 'error', 'error' => $exception->getMessage()];
            if ($activeSyncType !== null) {
                try {
                    $this->runtime->database->recordSyncRun(
                        type: $activeSyncType,
                        status: Database::SYNC_STATUS_ERROR,
                        message: $exception->getMessage()
                    );
                } catch (RuntimeException | PDOException) {
                }
            }
            try {
                $appendLog('ERROR', $exception->getMessage());
            } catch (RuntimeException) {
            }
        } finally {
            flock(stream: $lockHandle, operation: LOCK_UN);
            fclose(stream: $lockHandle);
        }
        http_response_code(response_code: $statusCode);
        echo json_encode(
            value: $response,
            flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit();
    }

    private function handleAction(): never
    {
        $token = (string) ($_POST['csrf'] ?? '');
        if (!hash_equals(known_string: $this->csrfToken(), user_string: $token)) {
            http_response_code(response_code: 419);
            exit('Ungültiges CSRF-Token.');
        }
        $action = (string) ($_POST['action'] ?? '');
        $year = max(2020, min(2100, (int) ($_POST['year'] ?? date(format: 'o'))));
        $week = max(1, min(53, (int) ($_POST['week'] ?? date(format: 'W'))));
        $search = trim(string: (string) ($_POST['search'] ?? ''));
        $ingredientFilterValue = (string) ($_POST['ingredients'] ?? 'mapped');
        $ingredientFilter = match ($ingredientFilterValue) {
            'all', 'unmapped' => $ingredientFilterValue,
            default => 'mapped'
        };
        $weekFilter = (string) ($_POST['week_filter'] ?? 'all');
        $category = trim(string: (string) ($_POST['category'] ?? ''));
        $sort = (string) ($_POST['sort'] ?? 'favorites_desc');
        try {
            if ($action === 'assign') {
                $this->runtime->database->assignRecipe(
                    recipeId: (int) ($_POST['recipe_id'] ?? 0),
                    year: $year,
                    week: $week
                );
                $this->flash(type: 'success', message: 'Rezept zu Kalenderwoche ' . $week . ' hinzugefügt.');
            }
            if ($action === 'remove') {
                $this->runtime->database->removeRecipe(
                    recipeId: (int) ($_POST['recipe_id'] ?? 0),
                    year: $year,
                    week: $week
                );
                $this->flash(type: 'success', message: 'Rezept aus Kalenderwoche ' . $week . ' entfernt.');
            }
            if ($action === 'reset') {
                if ((string) ($_POST['confirmation'] ?? '') !== 'DELETE') {
                    throw new RuntimeException(message: 'Gib zum Zurücksetzen DELETE ein.');
                }
                $deletedRecipes = $this->runtime->database->resetRecipes();
                $this->flash(
                    type: 'success',
                    message: $deletedRecipes . ' Rezepte und alle verbundenen Daten wurden gelöscht.'
                );
            }
        } catch (RuntimeException $exception) {
            $this->flash(type: 'error', message: $exception->getMessage());
        }
        $query = http_build_query(
            data: [
                'year' => $year,
                'week' => $week,
                'search' => $search,
                'ingredients' => $ingredientFilter,
                'week_filter' => $weekFilter,
                'category' => $category,
                'sort' => $sort
            ]
        );
        header(header: 'Location: /?' . $query, response_code: 303);
        exit();
    }

    private function handleFeedback(): never
    {
        header(header: 'Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(response_code: 405);
            echo json_encode(value: ['error' => 'Methode nicht erlaubt.'], flags: JSON_THROW_ON_ERROR);
            exit();
        }
        $token = (string) ($_POST['csrf'] ?? '');
        if (!hash_equals(known_string: $this->csrfToken(), user_string: $token)) {
            http_response_code(response_code: 419);
            echo json_encode(value: ['error' => 'Ungültiges CSRF-Token.'], flags: JSON_THROW_ON_ERROR);
            exit();
        }
        $recipeId = max(0, (int) ($_POST['recipe_id'] ?? 0));
        $action = (string) ($_POST['action'] ?? '');
        $user = $this->currentUser();
        try {
            if ($action === 'rate') {
                $rating = (int) ($_POST['rating'] ?? 0);
                if ($rating < 1 || $rating > 5) {
                    throw new RuntimeException(message: 'Die Bewertung muss zwischen 1 und 5 liegen.');
                }
                $this->runtime->database->saveRating(
                    recipeId: $recipeId,
                    userId: $user->id,
                    userEmail: $user->email,
                    rating: $rating
                );
                $summary = $this->runtime->database->ratingSummary(recipeId: $recipeId);
                echo json_encode(
                    value: [
                        'rating' => $rating,
                        'summary_html' => $this->ratingSummaryHtml(summary: $summary)
                    ],
                    flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                );
                exit();
            }
            if ($action === 'note') {
                $note = trim(string: (string) ($_POST['note'] ?? ''));
                if (mb_strlen(string: $note) > 5000) {
                    throw new RuntimeException(message: 'Die Notiz darf höchstens 5.000 Zeichen lang sein.');
                }
                $this->runtime->database->saveNote(
                    recipeId: $recipeId,
                    userId: $user->id,
                    userEmail: $user->email,
                    note: $note
                );
                echo json_encode(value: ['note' => $note], flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                exit();
            }
            throw new RuntimeException(message: 'Ungültige Aktion.');
        } catch (RuntimeException $exception) {
            http_response_code(response_code: 422);
            echo json_encode(
                value: ['error' => $exception->getMessage()],
                flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            );
            exit();
        }
    }

    private function handleTask(): never
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header(header: 'Location: /', response_code: 303);
            exit();
        }
        $token = (string) ($_POST['csrf'] ?? '');
        if (!hash_equals(known_string: $this->csrfToken(), user_string: $token)) {
            http_response_code(response_code: 419);
            exit('Ungültiges CSRF-Token.');
        }
        $taskId = (string) ($_POST['task_id'] ?? '');
        if ($taskId === '') {
            $action = (string) ($_POST['action'] ?? '');
            if (!in_array(needle: $action, haystack: ['scrape-recipes', 'scrape-ingredients', 'order'], strict: true)) {
                http_response_code(response_code: 400);
                exit('Ungültige Aufgabe.');
            }
            $year = max(2020, min(2100, (int) ($_POST['year'] ?? date(format: 'o'))));
            $week = max(1, min(53, (int) ($_POST['week'] ?? date(format: 'W'))));
            $taskId = bin2hex(string: random_bytes(length: 16));
            $cancellationPath = $this->taskCancellationPath(taskId: $taskId);
            if (is_file(filename: $cancellationPath)) {
                unlink(filename: $cancellationPath);
            }
            $task = [
                'action' => $action,
                'year' => $year,
                'week' => $week,
                'return_url' =>
                    '/?' .
                    http_build_query(
                        data: [
                            'year' => $year,
                            'week' => $week,
                            'search' => trim(string: (string) ($_POST['search'] ?? '')),
                            'ingredients' => (string) ($_POST['ingredients'] ?? 'mapped'),
                            'week_filter' => (string) ($_POST['week_filter'] ?? 'all'),
                            'category' => trim(string: (string) ($_POST['category'] ?? '')),
                            'sort' => (string) ($_POST['sort'] ?? 'favorites_desc')
                        ]
                    )
            ];
            $_SESSION['tasks'][$taskId] = $task;
            $this->renderTask(taskId: $taskId, task: $task);
        }
        $task = $_SESSION['tasks'][$taskId] ?? null;
        unset($_SESSION['tasks'][$taskId]);
        if (!is_array(value: $task)) {
            http_response_code(response_code: 409);
            header(header: 'Content-Type: application/x-ndjson; charset=utf-8');
            echo json_encode(
                value: ['type' => 'error', 'message' => 'Diese Aufgabe wurde bereits gestartet oder ist abgelaufen.'],
                flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            ) . "\n";
            exit();
        }
        session_write_close();
        set_time_limit(seconds: 0);
        ignore_user_abort(false);
        header(header: 'Content-Type: application/x-ndjson; charset=utf-8');
        header(header: 'Cache-Control: no-cache, no-store');
        header(header: 'X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_implicit_flush(enable: true);
        $action = (string) $task['action'];
        $year = (int) $task['year'];
        $week = (int) $task['week'];
        $activeSyncType = match ($action) {
            'scrape-recipes' => Database::SYNC_RECIPES,
            'scrape-ingredients' => Database::SYNC_INGREDIENTS,
            default => null
        };
        $taskProgress = 1;
        $cancellationPath = $this->taskCancellationPath(taskId: $taskId);
        register_shutdown_function(function () use ($cancellationPath): void {
            if (is_file(filename: $cancellationPath)) {
                unlink(filename: $cancellationPath);
            }
        });
        try {
            $completionType = 'success';
            $this->ensureTaskIsActive(taskId: $taskId);
            $this->sendProgress(progress: 1, message: 'Vorgang wird vorbereitet.');
            if ($action === 'scrape-recipes') {
                $result = $this->runtime->helloFreshScraper->scrapeRecipes(
                    progress: function (int $scanned, int $total, int $created, int $updated) use (
                        &$taskProgress,
                        $taskId
                    ): void {
                        $this->ensureTaskIsActive(taskId: $taskId);
                        $taskProgress = min(95, max(2, (int) round(($scanned / max(1, $total)) * 95)));
                        $this->sendProgress(
                            progress: $taskProgress,
                            message: sprintf('%d von %d Rezepten geprüft, %d neu.', $scanned, $total, $created)
                        );
                    },
                    checkpoint: function () use ($taskId): void {
                        $this->ensureTaskIsActive(taskId: $taskId);
                    }
                );
                $message = sprintf(
                    'HelloFresh aktualisiert: %d neue, %d vorhandene, %d ausgefilterte und %d nicht aufgelöste Rezepte.',
                    $result['created'],
                    $result['updated'],
                    $result['filtered'],
                    $result['unresolved']
                );
                $completionType = $result['unresolved'] === 0 ? 'success' : 'error';
                $this->runtime->database->recordSyncRun(
                    type: Database::SYNC_RECIPES,
                    status: $completionType === 'success' ? Database::SYNC_STATUS_SUCCESS : Database::SYNC_STATUS_ERROR,
                    message: $message
                );
                $activeSyncType = null;
            }
            if ($action === 'scrape-ingredients') {
                $result = $this->runtime->helloFreshScraper->scrapeIngredients(
                    reweClient: $this->runtime->reweClient,
                    progress: function (string $name, bool $success, int $current, int $total, string $error = '') use (
                        &$taskProgress,
                        $taskId
                    ): void {
                        $this->ensureTaskIsActive(taskId: $taskId);
                        $taskProgress = min(95, (int) round(($current / max(1, $total)) * 95));
                        $message = $success ? $name . ' wurde zugeordnet.' : $name . ': ' . $error;
                        $this->sendProgress(progress: $taskProgress, message: $message);
                    },
                    checkpoint: function () use ($taskId): void {
                        $this->ensureTaskIsActive(taskId: $taskId);
                    }
                );
                $message = sprintf(
                    '%d Rezepte zugeordnet, %d fehlgeschlagen.',
                    $result['processed'],
                    $result['failed']
                );
                if ($result['errors'] !== []) {
                    $message .= ' ' . implode(separator: ' ', array: $result['errors']);
                }
                $completionType = $result['failed'] === 0 ? 'success' : 'error';
                $this->runtime->database->recordSyncRun(
                    type: Database::SYNC_INGREDIENTS,
                    status: $completionType === 'success' ? Database::SYNC_STATUS_SUCCESS : Database::SYNC_STATUS_ERROR,
                    message: $this->ingredientSyncMessage(result: $result)
                );
                $activeSyncType = null;
            }
            if ($action === 'order') {
                $result = $this->runtime->reweClient->orderWeek(
                    year: $year,
                    week: $week,
                    progress: function (int $current, int $total, string $message) use (&$taskProgress, $taskId): void {
                        $this->ensureTaskIsActive(taskId: $taskId);
                        $taskProgress = min(95, max(2, (int) round(($current / max(1, $total)) * 95)));
                        $this->sendProgress(progress: $taskProgress, message: $message);
                    }
                );
                $this->runtime->database->saveOrder(year: $year, week: $week, status: 'completed', result: $result);
                $message = sprintf(
                    'REWE-Warenkorb ersetzt: %d Stück aus %d unterschiedlichen Produkten hinzugefügt.',
                    array_sum(array: array_column(array: $result['added'], column_key: 'quantity')),
                    count(value: $result['added'])
                );
            }
            $this->sendProgress(
                progress: 100,
                message: $message,
                type: $completionType,
                returnUrl: (string) $task['return_url']
            );
        } catch (TaskCancelledException) {
            if ($activeSyncType !== null) {
                try {
                    $this->runtime->database->recordSyncRun(
                        type: $activeSyncType,
                        status: Database::SYNC_STATUS_CANCELLED,
                        message: 'Der Lauf wurde manuell gestoppt.'
                    );
                } catch (RuntimeException | PDOException) {
                }
            }
            $this->sendProgress(
                progress: $taskProgress,
                message: 'Vorgang wurde gestoppt.',
                type: 'cancelled',
                returnUrl: (string) $task['return_url']
            );
        } catch (RuntimeException $exception) {
            if ($activeSyncType !== null) {
                try {
                    $this->runtime->database->recordSyncRun(
                        type: $activeSyncType,
                        status: Database::SYNC_STATUS_ERROR,
                        message: $exception->getMessage()
                    );
                } catch (RuntimeException | PDOException) {
                }
            }
            if ($action === 'order') {
                $this->runtime->database->saveOrder(
                    year: $year,
                    week: $week,
                    status: 'failed',
                    result: ['action' => $action, 'error' => $exception->getMessage()]
                );
            }
            $this->sendProgress(
                progress: 100,
                message: $exception->getMessage(),
                type: 'error',
                returnUrl: (string) $task['return_url']
            );
        }
        exit();
    }

    private function handleTaskCancellation(): never
    {
        header(header: 'Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(response_code: 405);
            echo json_encode(value: ['error' => 'Methode nicht erlaubt.'], flags: JSON_THROW_ON_ERROR);
            exit();
        }
        $token = (string) ($_POST['csrf'] ?? '');
        if (!hash_equals(known_string: $this->csrfToken(), user_string: $token)) {
            http_response_code(response_code: 419);
            echo json_encode(value: ['error' => 'Ungültiges CSRF-Token.'], flags: JSON_THROW_ON_ERROR);
            exit();
        }
        $taskId = (string) ($_POST['task_id'] ?? '');
        if (preg_match(pattern: '/^[a-f0-9]{32}$/', subject: $taskId) !== 1) {
            http_response_code(response_code: 422);
            echo json_encode(value: ['error' => 'Ungültige Aufgabe.'], flags: JSON_THROW_ON_ERROR);
            exit();
        }
        $directory = $this->runtime->root . '/.data/tasks';
        if (!is_dir(filename: $directory)) {
            mkdir(directory: $directory, permissions: 0770, recursive: true);
        }
        if (file_put_contents(filename: $this->taskCancellationPath(taskId: $taskId), data: 'cancel') === false) {
            http_response_code(response_code: 500);
            echo json_encode(
                value: ['error' => 'Der Vorgang konnte nicht gestoppt werden.'],
                flags: JSON_THROW_ON_ERROR
            );
            exit();
        }
        echo json_encode(value: ['cancelled' => true], flags: JSON_THROW_ON_ERROR);
        exit();
    }

    private function ensureTaskIsActive(string $taskId): void
    {
        if (is_file(filename: $this->taskCancellationPath(taskId: $taskId))) {
            throw new TaskCancelledException(message: 'Vorgang wurde gestoppt.');
        }
    }

    private function taskCancellationPath(string $taskId): string
    {
        return $this->runtime->root . '/.data/tasks/' . $taskId . '.cancel';
    }

    private function renderLogin(): never
    {
        $assets = $this->assets();
        echo <<<HTML
            <!doctype html>
            <html lang="de">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>mampf // Anmelden</title>
                <script>document.documentElement.classList.toggle('dark', localStorage.getItem('mampf-theme') === 'dark');</script>
                <link rel="manifest" href="/manifest.webmanifest">
                <meta name="theme-color" content="#047857">
                <meta name="mobile-web-app-capable" content="yes">
                <meta name="apple-mobile-web-app-capable" content="yes">
                <meta name="apple-mobile-web-app-status-bar-style" content="default">
                <meta name="apple-mobile-web-app-title" content="mampf">
                <link rel="apple-touch-icon" href="/apple-touch-icon.png">
                <link rel="icon" href="/favicon.svg" type="image/svg+xml">
                <link rel="stylesheet" href="{$assets['css']}">
                <script type="module" src="{$assets['js']}" defer></script>
            </head>
            <body class="min-h-screen bg-stone-100 text-stone-950">
                <main class="grid min-h-screen place-items-center px-5 py-12">
                    <section class="w-full max-w-sm rounded-lg border border-stone-200 bg-white p-7 shadow-sm">
                        <div class="mb-7 text-center">
                            <h1 class="text-xl font-semibold">mampf</h1>
                            <span class="mx-auto mt-3 grid size-11 place-items-center rounded-md bg-emerald-700 text-white"><i data-lucide="utensils" class="size-6"></i></span>
                        </div>
                        <form data-login-form class="space-y-4">
                            <label class="block text-sm font-medium">E-Mail<input name="email" type="email" autocomplete="username" required class="mt-1.5 w-full rounded-md border border-stone-300 px-3 py-2.5 outline-none focus:border-emerald-700 focus:ring-2 focus:ring-emerald-100"></label>
                            <label class="block text-sm font-medium">Passwort<input name="password" type="password" autocomplete="current-password" required class="mt-1.5 w-full rounded-md border border-stone-300 px-3 py-2.5 outline-none focus:border-emerald-700 focus:ring-2 focus:ring-emerald-100"></label>
                            <p data-login-error class="hidden rounded-md bg-red-50 px-3 py-2 text-sm text-red-800"></p>
                            <button class="flex w-full items-center justify-center gap-2 rounded-md bg-emerald-700 px-4 py-2.5 font-medium text-white hover:bg-emerald-800" type="submit"><i data-lucide="log-in" class="size-4"></i>Anmelden</button>
                        </form>
                    </section>
                </main>
            </body>
            </html>
        HTML;
        exit();
    }

    /** @param array<string, mixed> $task */
    private function renderTask(string $taskId, array $task): never
    {
        $assets = $this->assets();
        $csrf = $this->escape(value: $this->csrfToken());
        $taskIdValue = $this->escape(value: $taskId);
        $returnUrl = $this->escape(value: (string) $task['return_url']);
        $configuration = match ((string) $task['action']) {
            'scrape-recipes' => [
                'title' => 'Rezepte aktualisieren',
                'description' =>
                    'HelloFresh-Rezepte, Zutaten für drei Portionen und Beliebtheitswerte werden aktualisiert.',
                'icon' => 'refresh-cw'
            ],
            'scrape-ingredients' => [
                'title' => 'Zutaten zuordnen',
                'description' => 'Fehlende REWE-Produkte werden ergänzt und vorhandene Zuordnungen regelmäßig geprüft.',
                'icon' => 'scan-search'
            ],
            default => [
                'title' => 'Produkte dieser Woche bestellen',
                'description' => 'Der REWE-Warenkorb wird geleert und mit den Zutaten dieser Woche gefüllt.',
                'icon' => 'shopping-cart'
            ]
        };
        $title = $this->escape(value: $configuration['title']);
        $description = $this->escape(value: $configuration['description']);
        $icon = $this->escape(value: $configuration['icon']);
        $isOrder = (string) $task['action'] === 'order';
        $basketLink = $isOrder
            ? '<a data-task-basket href="https://www.rewe.de/shop/checkout/basket" target="_blank" rel="noopener noreferrer" class="mt-6 hidden w-full items-center justify-center gap-2 rounded-md bg-emerald-700 px-4 py-3 text-base font-semibold text-white hover:bg-emerald-800"><i data-lucide="shopping-cart" class="size-5"></i>Zum Warenkorb<i data-lucide="external-link" class="size-4"></i></a>'
            : '';
        $returnClasses = $isOrder
            ? 'mt-3 border border-stone-300 text-stone-700 hover:bg-stone-50'
            : 'mt-6 bg-emerald-700 text-white hover:bg-emerald-800';
        echo <<<HTML
            <!doctype html>
            <html lang="de">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>mampf // {$title}</title>
                <script>document.documentElement.classList.toggle('dark', localStorage.getItem('mampf-theme') === 'dark');</script>
                <link rel="manifest" href="/manifest.webmanifest">
                <meta name="theme-color" content="#047857">
                <meta name="mobile-web-app-capable" content="yes">
                <meta name="apple-mobile-web-app-capable" content="yes">
                <meta name="apple-mobile-web-app-status-bar-style" content="default">
                <meta name="apple-mobile-web-app-title" content="mampf">
                <link rel="apple-touch-icon" href="/apple-touch-icon.png">
                <link rel="icon" href="/favicon.svg" type="image/svg+xml">
                <link rel="stylesheet" href="{$assets['css']}">
                <script type="module" src="{$assets['js']}" defer></script>
            </head>
            <body class="min-h-screen bg-stone-100 text-stone-950">
                <header class="border-b border-stone-200 bg-white">
                    <div class="mx-auto flex max-w-screen-2xl items-center justify-between px-5 py-4">
                        <a href="{$returnUrl}" class="flex items-center gap-3"><span class="grid size-9 place-items-center rounded-md bg-emerald-700 text-white"><i data-lucide="utensils" class="size-5"></i></span><span class="text-lg font-semibold">mampf</span></a>
                        <button data-logout type="button" title="Abmelden" aria-label="Abmelden" class="grid size-9 place-items-center rounded-md border border-stone-300 text-stone-600 hover:bg-stone-100"><i data-lucide="log-out" class="size-4"></i></button>
                    </div>
                </header>
                <main class="grid min-h-[calc(100vh-74px)] place-items-center px-5 py-12">
                    <section data-task-panel class="w-full max-w-xl rounded-lg border border-stone-200 bg-white p-7 shadow-sm">
                        <div class="flex items-start gap-4">
                            <span data-task-icon class="grid size-11 shrink-0 place-items-center rounded-md bg-emerald-50 text-emerald-700"><i data-lucide="{$icon}" class="size-5 animate-pulse"></i></span>
                            <div>
                                <h1 class="text-xl font-semibold">{$title}</h1>
                                <p class="mt-1 text-sm text-stone-500">{$description}</p>
                            </div>
                        </div>
                        <div class="mt-7 h-2 overflow-hidden rounded-full bg-stone-100">
                            <div data-task-progress class="h-full w-[1%] rounded-full bg-emerald-700 transition-[width] duration-300"></div>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-4 text-xs font-medium text-stone-500"><span data-task-percentage class="tabular-nums">1 %</span><span data-task-time class="tabular-nums">0:00 vergangen · Restzeit wird berechnet</span></div>
                        <p data-task-status class="mt-2 min-h-5 text-sm text-stone-600">Vorgang wird gestartet.</p>
                        <button data-task-stop type="button" class="mt-6 flex w-full items-center justify-center gap-2 rounded-md border border-red-200 px-4 py-2.5 text-sm font-medium text-red-700 hover:bg-red-50"><i data-lucide="square" class="size-3.5 fill-current"></i>Stoppen</button>
                        {$basketLink}
                        <a data-task-return href="{$returnUrl}" class="hidden w-full items-center justify-center gap-2 rounded-md px-4 py-2.5 text-sm font-medium {$returnClasses}"><i data-lucide="arrow-left" class="size-4"></i>Zurück zum Wochenplan</a>
                        <form data-task-form class="hidden">
                            <input type="hidden" name="csrf" value="{$csrf}">
                            <input type="hidden" name="task_id" value="{$taskIdValue}">
                        </form>
                    </section>
                </main>
            </body>
            </html>
        HTML;
        exit();
    }

    private function sendProgress(
        int $progress,
        string $message,
        string $type = 'progress',
        ?string $returnUrl = null
    ): void {
        echo json_encode(
            value: [
                'type' => $type,
                'progress' => max(0, min(100, $progress)),
                'message' => $message,
                'return_url' => $returnUrl
            ],
            flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) .
            str_repeat(string: ' ', times: 8192) .
            "\n";
        flush();
    }

    /**
     * Render the authenticated dashboard.
     *
     * @param list<array<string, mixed>> $recipes
     */
    private function renderDashboard(
        array $recipes,
        int $total,
        int $weekRecipeCount,
        int $year,
        int $week,
        string $search,
        string $ingredientFilter,
        string $weekFilter,
        string $category,
        string $sort,
        int $page,
        int $pages,
        bool $partial
    ): never {
        $assets = $this->assets();
        $csrf = $this->escape(value: $this->csrfToken());
        $syncRuns = $this->runtime->database->syncRuns();
        $syncStatusHtml = '';
        foreach (
            [
                Database::SYNC_RECIPES => ['label' => 'Rezepte', 'name' => 'Rezept-Scrape'],
                Database::SYNC_INGREDIENTS => ['label' => 'Zutaten', 'name' => 'Zutaten-Scrape']
            ]
            as $syncType => $syncConfig
        ) {
            $syncRun = $syncRuns[$syncType];
            $syncCompletedAt = $syncRun['completed_at'];
            $syncStatus = $syncCompletedAt === null ? '' : $syncRun['status'];
            $syncStatusLabel = match ($syncStatus) {
                Database::SYNC_STATUS_SUCCESS => 'erfolgreich',
                Database::SYNC_STATUS_ERROR => 'fehlgeschlagen',
                Database::SYNC_STATUS_CANCELLED => 'abgebrochen',
                default => 'noch nie'
            };
            $syncStatusStyle = match ($syncStatus) {
                Database::SYNC_STATUS_SUCCESS => 'text-emerald-700',
                Database::SYNC_STATUS_ERROR => 'text-red-700',
                Database::SYNC_STATUS_CANCELLED => 'text-amber-700',
                default => 'text-stone-400'
            };
            $syncStatusIcon = match ($syncStatus) {
                Database::SYNC_STATUS_SUCCESS => 'success',
                Database::SYNC_STATUS_ERROR => 'error',
                default => 'info'
            };
            $syncTitle =
                $syncStatus === ''
                    ? 'Noch kein ' . $syncConfig['name']
                    : 'Letzter ' . $syncConfig['name'] . ' ' . $syncStatusLabel;
            $syncMessage = trim(string: $syncRun['message']);
            if ($syncMessage === '') {
                $syncMessage =
                    $syncStatus === Database::SYNC_STATUS_SUCCESS
                        ? 'Der letzte Lauf wurde ohne Fehler abgeschlossen.'
                        : 'Es ist noch kein Ergebnis vorhanden.';
            }
            $syncText =
                $syncConfig['label'] .
                ': ' .
                ($syncCompletedAt === null
                    ? 'noch nie'
                    : $this->formatSyncRunTime(timestamp: $syncCompletedAt) . ' · ' . $syncStatusLabel);
            if ($syncStatusHtml !== '') {
                $syncStatusHtml .= '<span class="text-stone-300"> · </span>';
            }
            $syncStatusHtml .=
                '<button type="button" data-sync-status data-sync-title="' .
                $this->escape(value: $syncTitle) .
                '" data-sync-message="' .
                $this->escape(value: $syncMessage) .
                '" data-sync-icon="' .
                $syncStatusIcon .
                '" class="' .
                $syncStatusStyle .
                '">' .
                $this->escape(value: $syncText) .
                '</button>';
        }
        $searchValue = $this->escape(value: $search);
        $filterFields =
            '<input type="hidden" name="search" value="' .
            $searchValue .
            '"><input type="hidden" name="ingredients" value="' .
            $this->escape(value: $ingredientFilter) .
            '"><input type="hidden" name="week_filter" value="' .
            $this->escape(value: $weekFilter) .
            '"><input type="hidden" name="category" value="' .
            $this->escape(value: $category) .
            '"><input type="hidden" name="sort" value="' .
            $this->escape(value: $sort) .
            '">';
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        $flashHtml = '';
        if (is_array(value: $flash)) {
            $colors = match ($flash['type'] ?? '') {
                'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
                'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
                default => 'border-red-200 bg-red-50 text-red-900'
            };
            $flashMessage = $this->escape(value: (string) ($flash['message'] ?? ''));
            $flashHtml =
                '<div class="border-b ' .
                $colors .
                '"><div class="mx-auto max-w-screen-2xl px-5 py-3 text-sm">' .
                $flashMessage .
                '</div></div>';
        }
        $recipeHtml = '';
        foreach ($recipes as $recipe) {
            $id = (int) $recipe['id'];
            $name = $this->escape(value: (string) $recipe['name']);
            $imageUrl = $this->escape(value: (string) $recipe['image_url']);
            $sourceUrl = $this->escape(value: (string) $recipe['source_url']);
            $pdfUrl = $this->escape(value: (string) ($recipe['pdf_url'] ?? ''));
            $pdfLink =
                $pdfUrl === ''
                    ? ''
                    : '<a href="' .
                        $pdfUrl .
                        '" target="_blank" rel="noopener noreferrer" title="Rezept als PDF öffnen" aria-label="Rezept als PDF öffnen" class="grid size-7 shrink-0 place-items-center rounded-md border border-red-200 text-red-700 hover:bg-red-50"><i data-lucide="file-text" class="size-4"></i></a>';
            $selected = (int) $recipe['selected'] === 1;
            $ingredientsKnown = $recipe['ingredients_json'] !== null;
            $ingredients = $ingredientsKnown
                ? json_decode(json: (string) $recipe['ingredients_json'], associative: true)
                : [];
            $ingredients = is_array(value: $ingredients) ? $ingredients : [];
            $recipeIngredientCount = (int) $recipe['ingredient_count'];
            $mappedIngredientCount = count(
                value: array_filter(
                    array: $ingredients,
                    callback: fn(mixed $ingredient): bool => is_array(value: $ingredient) &&
                        trim(string: (string) ($ingredient['selected']['listing_id'] ?? '')) !== ''
                )
            );
            $ingredientsComplete = $recipeIngredientCount > 0 && $mappedIngredientCount === $recipeIngredientCount;
            $favoritesCount = (int) $recipe['favorites_count'];
            $ratingsCount = (int) $recipe['ratings_count'];
            $averageRating =
                $ratingsCount > 0
                    ? number_format(num: (float) $recipe['average_rating'], decimals: 1, decimal_separator: ',')
                    : '–';
            $personalRating = (int) $recipe['personal_rating'];
            $note = (string) $recipe['global_note'];
            $communityRatings = json_decode(json: (string) $recipe['community_ratings_json'], associative: true);
            $communityRatings = is_array(value: $communityRatings) ? $communityRatings : [];
            $ratingSummary = [
                'average' => (float) $recipe['community_average_rating'],
                'count' => (int) $recipe['community_ratings_count'],
                'ratings' => $communityRatings
            ];
            $ratingButtons = '';
            for ($rating = 1; $rating <= 5; $rating++) {
                $ratingStyle = $rating <= $personalRating ? 'fill-current text-amber-500' : 'text-stone-300';
                $ratingButtons .=
                    '<button type="button" data-rating-button data-rating="' .
                    $rating .
                    '" title="' .
                    $rating .
                    ' von 5 Sternen" aria-label="' .
                    $rating .
                    ' von 5 Sternen" class="grid size-5 place-items-center hover:text-amber-500"><i data-lucide="star" class="size-4 ' .
                    $ratingStyle .
                    '"></i></button>';
            }
            $noteStyle =
                $note === '' ? 'text-stone-400 hover:text-stone-700' : 'text-emerald-700 hover:text-emerald-900';
            $noteTitle = $note === '' ? 'Notiz hinzufügen' : 'Notiz bearbeiten';
            $ratingSummaryHtml = $this->ratingSummaryHtml(summary: $ratingSummary);
            $noteHtml = $this->escape(value: $note);
            $status =
                '<span class="inline-flex items-center gap-1 text-xs text-stone-500"><i data-lucide="circle-dashed" class="size-3.5"></i>Noch nicht zugeordnet</span>';
            if ($ingredientsKnown) {
                $ingredientRows = '';
                foreach ($ingredients as $ingredient) {
                    if (!is_array(value: $ingredient)) {
                        continue;
                    }
                    $ingredientName = $this->escape(value: (string) ($ingredient['name'] ?? 'Unbekannte Zutat'));
                    $amount = trim(
                        string: (string) ($ingredient['amount'] ?? '') . ' ' . (string) ($ingredient['unit'] ?? '')
                    );
                    $amountHtml =
                        $amount !== ''
                            ? '<span class="block text-xs tabular-nums text-stone-500">' .
                                $this->escape(value: $amount) .
                                '</span>'
                            : '';
                    $selectedProduct = is_array(value: $ingredient['selected'] ?? null)
                        ? $ingredient['selected']
                        : null;
                    $productName = trim(string: (string) ($selectedProduct['name'] ?? ''));
                    $productUrl = trim(string: (string) ($selectedProduct['url'] ?? ''));
                    $productHtml = '<span class="text-stone-400">Nicht zugeordnet</span>';
                    if ($productName !== '' && $productUrl !== '') {
                        $productHtml =
                            '<a href="' .
                            $this->escape(value: $productUrl) .
                            '" target="_blank" rel="noopener noreferrer" class="inline-flex items-start gap-1.5 font-medium text-emerald-700 hover:text-emerald-900 hover:underline"><span>' .
                            $this->escape(value: $productName) .
                            '</span><i data-lucide="external-link" class="mt-0.5 size-3 shrink-0"></i></a>';
                    }
                    $ingredientRows .=
                        '<div class="grid grid-cols-2 gap-4 border-t border-stone-100 px-4 py-2.5 text-sm"><div>' .
                        $amountHtml .
                        '<span class="font-medium text-stone-800">' .
                        $ingredientName .
                        '</span></div><div>' .
                        $productHtml .
                        '</div></div>';
                }
                $statusStyle = $ingredientsComplete
                    ? 'text-emerald-700 hover:text-emerald-900'
                    : 'text-amber-700 hover:text-amber-900';
                $statusIcon = $ingredientsComplete ? 'circle-check' : 'circle-alert';
                $status =
                    '<button type="button" data-ingredients-trigger data-hover-trigger aria-expanded="false" class="inline-flex items-center gap-1 text-xs font-medium ' .
                    $statusStyle .
                    '"><i data-lucide="' .
                    $statusIcon .
                    '" class="size-3.5"></i>' .
                    $mappedIngredientCount .
                    '/' .
                    $recipeIngredientCount .
                    ' Zutaten</button><template data-ingredients-template data-hover-template><div class="border-b border-stone-100 px-4 py-3"><p class="text-xs font-medium uppercase text-emerald-700">Zutatenzuordnung</p><p class="mt-0.5 font-semibold text-stone-950">' .
                    $name .
                    '</p></div><div class="grid grid-cols-2 gap-4 bg-stone-50 px-4 py-2 text-xs font-semibold text-stone-500"><span>Rezept</span><span>REWE-Produkt</span></div><div>' .
                    $ingredientRows .
                    '</div></template>';
            }
            $action = $selected ? 'remove' : 'assign';
            $buttonText = $selected ? 'Entfernen' : 'Hinzufügen';
            $buttonIcon = $selected ? 'minus' : 'plus';
            $buttonStyle = $selected
                ? 'border-emerald-700 bg-emerald-700 text-white hover:bg-emerald-800'
                : 'border-stone-300 bg-white text-stone-700 hover:bg-stone-50';
            $buttonDisabled = '';
            $buttonTitle = $buttonText;
            if (!$selected && !$ingredientsComplete) {
                $buttonStyle = 'border-stone-200 bg-stone-100 text-stone-400 disabled:cursor-not-allowed';
                $buttonDisabled = ' disabled aria-disabled="true"';
                $buttonTitle = 'Das Rezept muss zuerst vollständig zugeordnet werden';
            }
            $recipeHtml .= <<<HTML
                <article data-recipe-id="{$id}" class="overflow-hidden rounded-lg border border-stone-200 bg-white">
                    <a href="{$sourceUrl}" target="_blank" rel="noopener noreferrer" class="block aspect-[2/1] overflow-hidden bg-stone-100 sm:aspect-[4/3]">
                        <img src="{$imageUrl}" alt="{$name}" loading="lazy" class="size-full object-cover transition duration-200 hover:scale-[1.02]">
                    </a>
                    <div class="p-3 sm:p-4">
                        <div class="flex items-start gap-2 sm:min-h-12"><h2 class="line-clamp-2 flex-1 text-sm font-semibold leading-5 sm:text-base sm:leading-6"><a href="{$sourceUrl}" target="_blank" rel="noopener noreferrer" class="hover:text-emerald-700 hover:underline">{$name}</a></h2>{$pdfLink}</div>
                        <div class="mt-0.5 flex min-h-5 items-center justify-between gap-3 sm:mt-2">
                            {$status}
                            <span class="flex shrink-0 items-center gap-2 text-xs text-stone-500"><span title="{$favoritesCount} Favoriten" class="inline-flex items-center gap-1"><i data-lucide="heart" class="size-3.5"></i>{$favoritesCount}</span><span title="{$ratingsCount} Bewertungen" class="inline-flex items-center gap-1"><i data-lucide="star" class="size-3.5"></i>{$averageRating}</span></span>
                        </div>
                        <div class="mt-1.5 flex min-h-5 items-center justify-between gap-3 border-t border-stone-100 pt-1.5 sm:mt-2 sm:pt-2">
                            <div data-rating-picker class="flex items-center" aria-label="Eigene Bewertung">{$ratingButtons}</div>
                            <div class="flex items-center gap-2">
                                <span data-rating-summary class="relative -top-px">{$ratingSummaryHtml}</span>
                                <button type="button" data-note-button title="{$noteTitle}" aria-label="{$noteTitle}" class="grid size-6 place-items-center {$noteStyle}"><i data-lucide="notebook-pen" class="size-4"></i></button>
                                <template data-note-template>{$noteHtml}</template>
                            </div>
                        </div>
                        <form method="post" class="mt-3 sm:mt-4">
                            <input type="hidden" name="csrf" value="{$csrf}"><input type="hidden" name="action" value="{$action}"><input type="hidden" name="recipe_id" value="{$id}"><input type="hidden" name="year" value="{$year}"><input type="hidden" name="week" value="{$week}">{$filterFields}
                            <button type="submit" title="{$buttonTitle}" class="flex w-full items-center justify-center gap-2 rounded-md border px-3 py-1.5 text-sm font-medium sm:py-2 {$buttonStyle}"{$buttonDisabled}><i data-lucide="{$buttonIcon}" class="size-4"></i>{$buttonText}</button>
                        </form>
                    </div>
                </article>
            HTML;
        }
        if ($recipeHtml === '') {
            $recipeHtml =
                '<div class="col-span-full border-y border-stone-200 py-16 text-center text-stone-500">Keine Rezepte gefunden.</div>';
        }
        if ($partial) {
            header(header: 'Content-Type: application/json');
            echo json_encode(
                value: [
                    'html' => $recipeHtml,
                    'has_more' => $page < $pages,
                    'next_page' => $page + 1
                ],
                flags: JSON_THROW_ON_ERROR
            );
            exit();
        }
        $lazyLoaderHtml =
            $page < $pages
                ? '<div data-lazy-loader data-next-page="' .
                    ($page + 1) .
                    '" class="flex h-16 items-center justify-center text-stone-400" aria-label="Weitere Rezepte laden"><i data-lucide="loader-circle" class="size-5 animate-spin"></i></div>'
                : '';
        $ingredientOptions =
            $this->option(value: 'mapped', label: 'Zutaten zugeordnet', selected: $ingredientFilter) .
            $this->option(value: 'unmapped', label: 'Zutaten unvollständig', selected: $ingredientFilter) .
            $this->option(value: 'all', label: 'Alle Rezepte', selected: $ingredientFilter);
        $weekOptions =
            $this->option(value: 'all', label: 'Alle Rezepte', selected: $weekFilter) .
            $this->option(value: 'selected', label: 'Ausgewählte Rezepte', selected: $weekFilter) .
            $this->option(value: 'unselected', label: 'Nicht ausgewählte Rezepte', selected: $weekFilter);
        $weekTiles = '';
        $weekCounts = $this->runtime->database->weekRecipeCounts();
        $now = new DateTimeImmutable(datetime: 'now');
        $currentWeekStart = $now->setISODate(
            year: (int) $now->format(format: 'o'),
            week: (int) $now->format(format: 'W')
        );
        for ($weekOffset = -2; $weekOffset <= 4; $weekOffset++) {
            $weekStart = $currentWeekStart->modify(modifier: ($weekOffset >= 0 ? '+' : '') . $weekOffset . ' weeks');
            $tileYear = (int) $weekStart->format(format: 'o');
            $tileWeek = (int) $weekStart->format(format: 'W');
            $countKey = sprintf('%04d-W%02d', $tileYear, $tileWeek);
            $tileCount = $weekCounts[$countKey] ?? 0;
            $tileCountLabel = $tileCount === 1 ? '1 Gericht' : $tileCount . ' Gerichte';
            $dateLabel =
                $weekStart->format(format: 'd.m.') .
                '–' .
                $weekStart->modify(modifier: '+6 days')->format(format: 'd.m.');
            $isActiveWeek = $tileYear === $year && $tileWeek === $week;
            $tileStyle = match (true) {
                $isActiveWeek => 'border-emerald-700 bg-emerald-700 text-white',
                $weekOffset === 0 => 'border-emerald-300 bg-emerald-50 text-emerald-900',
                default => 'border-stone-200 bg-white text-stone-700 hover:border-emerald-300 hover:bg-emerald-50'
            };
            $tileQuery = $this->escape(
                value: http_build_query(
                    data: [
                        'year' => $tileYear,
                        'week' => $tileWeek,
                        'search' => $search,
                        'ingredients' => $ingredientFilter,
                        'week_filter' => $weekFilter,
                        'category' => $category,
                        'sort' => $sort
                    ]
                )
            );
            $weekTiles .=
                '<a href="/?' .
                $tileQuery .
                '" title="Kalenderwoche ' .
                $tileWeek .
                ', ' .
                $tileYear .
                '" class="flex w-[4.75rem] shrink-0 flex-col items-center rounded-md border px-1 py-1 text-center lg:w-[5.25rem] lg:px-2 lg:py-1.5 ' .
                $tileStyle .
                '"><span class="whitespace-nowrap text-[9px] leading-3 opacity-75 lg:text-[10px]">' .
                $dateLabel .
                '</span><strong class="text-xs leading-4 lg:mt-0.5 lg:text-sm">KW ' .
                $tileWeek .
                '</strong><span class="text-[9px] leading-3 opacity-80 lg:mt-0.5 lg:text-[10px]">' .
                $tileCountLabel .
                '</span></a>';
        }
        $sortOptions =
            $this->option(value: 'favorites_desc', label: 'Beliebteste', selected: $sort) .
            $this->option(value: 'ratings_desc', label: 'Meiste Bewertungen', selected: $sort) .
            $this->option(value: 'rating_desc', label: 'Beste Bewertung', selected: $sort) .
            $this->option(value: 'name_asc', label: 'Name A–Z', selected: $sort) .
            $this->option(value: 'name_desc', label: 'Name Z–A', selected: $sort) .
            $this->option(value: 'created_desc', label: 'Zuletzt importiert', selected: $sort);
        $categoryOptions = $this->option(value: '', label: 'Alle Kategorien', selected: $category);
        foreach ($this->runtime->database->categories() as $categoryName) {
            $categoryOptions .= $this->option(value: $categoryName, label: $categoryName, selected: $category);
        }
        $selectedWeekStart = new DateTimeImmutable(datetime: 'now')->setISODate(year: $year, week: $week);
        $selectedWeekDateLabel =
            $selectedWeekStart->format(format: 'd.m.y') .
            '-' .
            $selectedWeekStart->modify(modifier: '+6 days')->format(format: 'd.m.y');
        $orderDisabled = $weekRecipeCount === 0 ? ' disabled aria-disabled="true"' : '';
        $orderTitle =
            $weekRecipeCount === 0
                ? 'Füge dieser Woche zuerst ein Rezept hinzu'
                : 'Ausgewählte Woche bei REWE bestellen';
        $orderStyle =
            $weekRecipeCount === 0 ? 'bg-stone-300 text-stone-500' : 'bg-emerald-700 text-white hover:bg-emerald-800';
        $filterControls = <<<HTML
            <input type="hidden" name="year" value="{$year}"><input type="hidden" name="week" value="{$week}">
            <label class="relative flex-1"><i data-lucide="search" class="pointer-events-none absolute left-3 top-2.5 size-4 text-stone-400"></i><input name="search" value="{$searchValue}" placeholder="Rezepte suchen" aria-label="Rezepte suchen" class="w-full rounded-md border border-stone-300 bg-white py-2 pl-9 pr-3 text-sm outline-none focus:border-emerald-700"></label>
            <select name="category" aria-label="Kategorie" class="rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">{$categoryOptions}</select>
            <select name="ingredients" aria-label="Zutatenstatus" class="rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">{$ingredientOptions}</select>
            <select name="week_filter" aria-label="Wochenstatus" class="rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">{$weekOptions}</select>
            <select name="sort" aria-label="Sortierung" class="rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">{$sortOptions}</select>
            <div class="flex gap-2 sm:col-span-2 lg:col-span-1">
                <a href="/?year={$year}&amp;week={$week}" title="Filter zurücksetzen" aria-label="Filter zurücksetzen" class="grid size-9 shrink-0 place-items-center rounded-md border border-stone-300 bg-white text-stone-600 hover:bg-stone-50"><i data-lucide="filter-x" class="size-4"></i></a>
                <button class="flex-1 rounded-md border border-stone-300 bg-white px-4 py-2 text-sm font-medium hover:bg-stone-50">Filter</button>
            </div>
        HTML;
        echo <<<HTML
            <!doctype html>
            <html lang="de">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>mampf // KW {$week}</title>
                <script>document.documentElement.classList.toggle('dark', localStorage.getItem('mampf-theme') === 'dark');</script>
                <link rel="manifest" href="/manifest.webmanifest">
                <meta name="theme-color" content="#047857">
                <meta name="mobile-web-app-capable" content="yes">
                <meta name="apple-mobile-web-app-capable" content="yes">
                <meta name="apple-mobile-web-app-status-bar-style" content="default">
                <meta name="apple-mobile-web-app-title" content="mampf">
                <link rel="apple-touch-icon" href="/apple-touch-icon.png">
                <link rel="icon" href="/favicon.svg" type="image/svg+xml">
                <link rel="stylesheet" href="{$assets['css']}">
                <script type="module" src="{$assets['js']}" defer></script>
            </head>
            <body data-csrf="{$csrf}" class="min-h-screen bg-stone-50 text-stone-950">
                <header class="border-b border-stone-200 bg-white">
                    <div class="mx-auto grid max-w-screen-2xl grid-cols-1 items-center px-3 py-2 sm:px-5 lg:grid-cols-[1fr_auto_1fr] lg:gap-2 lg:py-3">
                        <a href="/" class="hidden items-center gap-3 justify-self-start lg:flex"><span class="grid size-9 place-items-center rounded-md bg-emerald-700 text-white"><i data-lucide="utensils" class="size-5"></i></span><span class="text-lg font-semibold">mampf</span></a>
                        <nav aria-label="Kalenderwoche auswählen" class="mx-auto flex w-full min-w-0 max-w-full gap-1 overflow-x-auto py-0.5 lg:gap-1.5 lg:px-1 lg:py-1">{$weekTiles}</nav>
                        <div class="hidden justify-items-end gap-1 justify-self-end lg:grid">
                            <div class="flex items-center gap-1">
                                <form method="post" action="/task" data-confirm-title="Rezepte aktualisieren?" data-confirm="Alle HelloFresh-Rezepte, Zutaten für drei Portionen und PDF-Links werden aktualisiert." data-confirm-button="Aktualisieren" class="hidden lg:block"><input type="hidden" name="csrf" value="{$csrf}"><input type="hidden" name="action" value="scrape-recipes"><input type="hidden" name="year" value="{$year}"><input type="hidden" name="week" value="{$week}">{$filterFields}<button title="Rezepte aktualisieren" aria-label="Rezepte aktualisieren" class="grid size-8 place-items-center rounded-md border border-stone-300 text-stone-600 hover:bg-stone-100"><i data-lucide="refresh-cw" class="size-4"></i></button></form>
                                <form method="post" action="/task" data-confirm-title="Zutaten zuordnen?" data-confirm="Alle fehlenden und veralteten REWE-Produktzuordnungen werden geprüft und aktualisiert." data-confirm-button="Zuordnen" class="hidden lg:block"><input type="hidden" name="csrf" value="{$csrf}"><input type="hidden" name="action" value="scrape-ingredients"><input type="hidden" name="year" value="{$year}"><input type="hidden" name="week" value="{$week}">{$filterFields}<button title="Zutaten zuordnen" aria-label="Zutaten zuordnen" class="grid size-8 place-items-center rounded-md border border-stone-300 text-stone-600 hover:bg-stone-100"><i data-lucide="scan-search" class="size-4"></i></button></form>
                                <form method="post" data-confirm-title="Alles zurücksetzen?" data-confirm="Alle Rezepte, Zutatenzuordnungen, Bewertungen, Notizen und Bestellungen werden unwiderruflich gelöscht." data-confirm-button="SICHER LÖSCHEN" data-confirm-icon="error" data-confirm-input="DELETE" class="hidden lg:block"><input type="hidden" name="csrf" value="{$csrf}"><input type="hidden" name="action" value="reset"><input type="hidden" name="year" value="{$year}"><input type="hidden" name="week" value="{$week}">{$filterFields}<button title="Alle Rezeptdaten löschen" aria-label="Alle Rezeptdaten löschen" class="grid size-8 place-items-center rounded-md border border-red-200 text-red-700 hover:bg-red-50"><i data-lucide="trash-2" class="size-4"></i></button></form>
                                <button data-theme-toggle type="button" title="Dark Mode aktivieren" aria-label="Dark Mode aktivieren" class="hidden size-8 place-items-center rounded-md border border-stone-300 text-stone-600 hover:bg-stone-100 lg:grid"><i data-lucide="moon" class="size-4"></i></button>
                                <button data-logout type="button" title="Abmelden" aria-label="Abmelden" class="grid size-8 place-items-center rounded-md border border-stone-300 text-stone-600 hover:bg-stone-100"><i data-lucide="log-out" class="size-4"></i></button>
                            </div>
                            <div class="hidden items-center whitespace-nowrap text-[10px] leading-3 lg:flex">{$syncStatusHtml}</div>
                        </div>
                    </div>
                </header>
                {$flashHtml}
                <main>
                    <section class="border-b border-stone-200 bg-white">
                        <div class="mx-auto grid max-w-screen-2xl gap-3 px-3 py-3 sm:px-5 lg:grid-cols-[1fr_auto] lg:items-end lg:gap-5 lg:py-6">
                            <div class="flex items-center justify-between gap-3 lg:block"><p class="text-xs font-medium leading-5 text-emerald-700 sm:text-sm">KW <span class="tabular-nums">{$week}</span> <span class="tabular-nums">({$selectedWeekDateLabel})</span></p><div class="text-sm text-stone-500 lg:mt-1"><span><strong class="text-stone-900">{$total}</strong> Rezepte</span></div></div>
                            <div class="flex w-full flex-wrap gap-2 lg:w-auto">
                                <form method="post" action="/task" data-confirm-title="Woche {$week} bestellen?" data-confirm="Der aktuelle REWE-Warenkorb wird vollständig geleert und durch die Zutaten dieser Woche ersetzt." data-confirm-button="Jetzt bestellen!" class="w-full lg:w-auto"><input type="hidden" name="csrf" value="{$csrf}"><input type="hidden" name="action" value="order"><input type="hidden" name="year" value="{$year}"><input type="hidden" name="week" value="{$week}">{$filterFields}<button title="{$orderTitle}" class="flex w-full items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-medium lg:w-auto {$orderStyle}"{$orderDisabled}><i data-lucide="shopping-cart" class="size-4"></i>Produkte dieser Woche bestellen</button></form>
                            </div>
                        </div>
                    </section>
                    <section class="border-b border-stone-200 bg-stone-100/70">
                        <div class="mx-auto max-w-screen-2xl px-3 py-3 sm:px-5 lg:py-4">
                            <details class="group lg:hidden"><summary class="flex cursor-pointer list-none items-center justify-between rounded-md border border-stone-300 bg-white px-3 py-2 text-sm font-medium"><span class="flex items-center gap-2"><i data-lucide="sliders-horizontal" class="size-4"></i>Filter und Sortierung</span><i data-lucide="chevron-down" class="size-4 transition-transform group-open:rotate-180"></i></summary><form method="get" class="mt-3 grid gap-2">{$filterControls}</form></details>
                            <form method="get" class="hidden gap-2 lg:grid lg:grid-cols-[minmax(15rem,1fr)_auto_auto_auto_auto_auto]">{$filterControls}</form>
                        </div>
                    </section>
                    <section class="mx-auto max-w-screen-2xl px-3 py-4 sm:px-5 lg:py-6">
                        <div data-recipe-grid class="grid gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6">{$recipeHtml}</div>
                        {$lazyLoaderHtml}
                    </section>
                </main>
                <div data-hover-popover data-ingredients-popover role="tooltip" class="fixed z-50 hidden max-h-[min(28rem,calc(100vh-1rem))] w-[min(38rem,calc(100vw-1rem))] overflow-y-auto rounded-lg border border-stone-200 bg-white shadow-xl"></div>
                <dialog data-note-dialog class="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-lg border border-stone-200 bg-white p-0 text-stone-950 shadow-xl backdrop:bg-stone-950/30">
                    <form data-note-form class="p-5">
                        <div class="flex items-start justify-between gap-4"><div><p class="text-xs font-medium uppercase text-emerald-700">Notiz</p><h2 data-note-title class="mt-0.5 font-semibold"></h2></div><button type="button" data-note-close title="Schließen" aria-label="Schließen" class="grid size-8 shrink-0 place-items-center rounded-md text-stone-500 hover:bg-stone-100"><i data-lucide="x" class="size-4"></i></button></div>
                        <input type="hidden" name="recipe_id" value="">
                        <textarea name="note" rows="7" maxlength="5000" aria-label="Notiz" class="mt-4 w-full resize-y rounded-md border border-stone-300 px-3 py-2 text-sm outline-none focus:border-emerald-700"></textarea>
                        <p data-note-error class="mt-2 hidden text-sm text-red-700"></p>
                        <div class="mt-4 flex justify-end gap-2"><button type="button" data-note-close class="rounded-md border border-stone-300 px-3 py-2 text-sm font-medium hover:bg-stone-50">Abbrechen</button><button type="submit" class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-800">Speichern</button></div>
                    </form>
                </dialog>
            </body>
            </html>
        HTML;
        exit();
    }

    /** @param array{average: float, count: int, ratings: array<mixed>} $summary */
    private function ratingSummaryHtml(array $summary): string
    {
        if ($summary['count'] === 0) {
            return '<span title="Noch keine Bewertungen" class="text-xs text-stone-400">Ø –</span>';
        }
        $rows = '';
        foreach ($summary['ratings'] as $rating) {
            if (!is_array(value: $rating)) {
                continue;
            }
            $value = max(1, min(5, (int) ($rating['rating'] ?? 0)));
            $rows .=
                '<div class="flex items-center justify-between gap-5 border-t border-stone-100 px-4 py-2.5 text-sm"><span class="truncate text-stone-700">' .
                $this->escape(value: (string) ($rating['email'] ?? '')) .
                '</span><span class="shrink-0 font-medium tabular-nums text-amber-600">' .
                $value .
                '/5</span></div>';
        }
        $average = number_format(num: $summary['average'], decimals: 1, decimal_separator: ',');
        return '<button type="button" data-hover-trigger data-popover-size="small" aria-expanded="false" title="Bewertungen anzeigen" class="inline-flex items-center gap-1 text-xs font-medium text-stone-500 hover:text-stone-800"><span>Ø ' .
            $average .
            '</span><span>(' .
            $summary['count'] .
            ')</span></button><template data-hover-template><div class="border-b border-stone-100 px-4 py-3"><p class="text-xs font-medium uppercase text-emerald-700">Bewertungen</p><p class="mt-0.5 font-semibold text-stone-950">Durchschnitt ' .
            $average .
            ' von 5</p></div><div>' .
            $rows .
            '</div></template>';
    }

    private function currentUser(): UserIdentity
    {
        $userId = (string) $this->runtime->auth->getCurrentUserId();
        foreach ($this->runtime->auth->getUsers() as $user) {
            if ((string) ($user['id'] ?? '') !== $userId) {
                continue;
            }
            return new UserIdentity(id: $userId, email: (string) ($user['login'] ?? ''));
        }
        throw new RuntimeException(message: 'Der angemeldete Benutzer konnte nicht geladen werden.');
    }

    /** @param array{processed: int, failed: int, errors: list<string>} $result */
    private function ingredientSyncMessage(array $result): string
    {
        $message = sprintf('%d Rezepte verarbeitet, %d fehlgeschlagen.', $result['processed'], $result['failed']);
        $errors = array_slice(array: $result['errors'], offset: 0, length: 5);
        if ($errors !== []) {
            $message .= ' ' . implode(separator: ' ', array: $errors);
        }
        $remainingErrorCount = count(value: $result['errors']) - count(value: $errors);
        if ($remainingErrorCount > 0) {
            $message .= ' Weitere ' . $remainingErrorCount . ' Fehler.';
        }
        return $message;
    }

    private function formatSyncRunTime(?string $timestamp): string
    {
        if ($timestamp === null) {
            return 'noch nie';
        }
        return new DateTimeImmutable(datetime: $timestamp, timezone: new DateTimeZone(timezone: 'UTC'))
            ->setTimezone(timezone: new DateTimeZone(timezone: date_default_timezone_get()))
            ->format(format: 'd.m. H:i');
    }

    /** @return array{css: string, js: string} */
    private function assets(): array
    {
        return ['css' => '/app.css', 'js' => '/app.js'];
    }

    private function csrfToken(): string
    {
        if (!isset($_SESSION['csrf']) || !is_string(value: $_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(string: random_bytes(length: 32));
        }
        return $_SESSION['csrf'];
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    private function option(string $value, string $label, string $selected): string
    {
        $selectedAttribute = $value === $selected ? ' selected' : '';
        return '<option value="' .
            $this->escape(value: $value) .
            '"' .
            $selectedAttribute .
            '>' .
            $this->escape(value: $label) .
            '</option>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(string: $value, flags: ENT_QUOTES | ENT_SUBSTITUTE, encoding: 'UTF-8');
    }
}
