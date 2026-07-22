import 'sweetalert2/dist/sweetalert2.min.css';
import './app.css';
import { createIcons, icons } from 'lucide';
import Swal from 'sweetalert2';

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
}

let $themeToggle = document.querySelector('[data-theme-toggle]');
if ($themeToggle !== null) {
    let syncThemeToggle = () => {
        let dark = document.documentElement.classList.contains('dark');
        $themeToggle.title = dark ? 'Dark Mode deaktivieren' : 'Dark Mode aktivieren';
        $themeToggle.setAttribute('aria-label', $themeToggle.title);
        $themeToggle.innerHTML = `<i data-lucide="${dark ? 'sun' : 'moon'}" class="size-4"></i>`;
    };
    syncThemeToggle();
    $themeToggle.addEventListener('click', () => {
        let dark = !document.documentElement.classList.contains('dark');
        document.documentElement.classList.toggle('dark', dark);
        localStorage.setItem('mampf-theme', dark ? 'dark' : 'light');
        syncThemeToggle();
        createIcons({ icons });
    });
}

createIcons({ icons });

let showError = message =>
    Swal.fire({
        title: 'Fehler',
        text: message,
        icon: 'error',
        confirmButtonText: 'OK',
        confirmButtonColor: '#047857'
    });

document.querySelectorAll('[data-sync-status]').forEach($button => {
    $button.addEventListener('click', () => {
        Swal.fire({
            title: $button.dataset.syncTitle,
            text: $button.dataset.syncMessage,
            icon: $button.dataset.syncIcon,
            confirmButtonText: 'OK',
            confirmButtonColor: '#047857'
        });
    });
});

let $cronStatus = document.querySelector('[data-cron-status]');
if ($cronStatus !== null) {
    let $cronRow = document.querySelector('[data-cron-row]');
    let updateCronStatus = async () => {
        try {
            let response = await fetch('/cron/status');
            if (!response.ok) {
                return;
            }
            let status = await response.json();
            let startedLabel = typeof status.started_label === 'string' ? status.started_label : '';
            let completedLabel = typeof status.completed_label === 'string' ? status.completed_label : '';
            let visible = status.running === true || completedLabel !== '';
            $cronRow?.classList.toggle('hidden', !visible);
            if (!visible) {
                return;
            }
            let running = status.running === true;
            let success = status.status === 'success';
            $cronStatus.classList.toggle('text-sky-700', running);
            $cronStatus.classList.toggle('text-emerald-700', !running && success);
            $cronStatus.classList.toggle('text-red-700', !running && !success);
            $cronStatus.textContent = running
                ? `Cron: läuft${startedLabel === '' ? '' : ` seit ${startedLabel}`} · ⚠️`
                : `Cron: ${completedLabel} · ${success ? '✅' : 'fehlgeschlagen'}`;
            $cronStatus.dataset.syncTitle = running ? 'Cron-Aktualisierung läuft' : 'Letzte Cron-Aktualisierung';
            $cronStatus.dataset.syncMessage = running
                ? `Der Cronjob läuft${startedLabel === '' ? '' : ` seit ${startedLabel}`} im Hintergrund.`
                : status.message || 'Die letzte Cron-Aktualisierung wurde abgeschlossen.';
            $cronStatus.dataset.syncIcon = running ? 'info' : success ? 'success' : 'error';
        } catch {
        }
    };
    window.setInterval(updateCronStatus, 15000);
}

let $loginForm = document.querySelector('[data-login-form]');
if ($loginForm !== null) {
    $loginForm.addEventListener('submit', async event => {
        event.preventDefault();
        let $error = document.querySelector('[data-login-error]');
        let $button = $loginForm.querySelector('button[type="submit"]');
        $button.disabled = true;
        $button.classList.add('opacity-60');
        $error.classList.add('hidden');
        let formData = new FormData($loginForm);
        try {
            let response = await fetch('/auth/login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: formData.get('email'), password: formData.get('password') })
            });
            let result = await response.json();
            if (!response.ok || result?.data?.access_token === undefined) {
                throw new Error('Anmeldung fehlgeschlagen.');
            }
            let cookieResponse = await fetch('/auth/cookie', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ access_token: result.data.access_token })
            });
            if (!cookieResponse.ok) {
                throw new Error('Der Login-Cookie konnte nicht erstellt werden.');
            }
            window.location.reload();
        } catch (error) {
            $error.textContent = error instanceof Error ? error.message : 'Anmeldung fehlgeschlagen.';
            $error.classList.remove('hidden');
            $button.disabled = false;
            $button.classList.remove('opacity-60');
        }
    });
}

let $logout = document.querySelector('[data-logout]');
if ($logout !== null) {
    $logout.addEventListener('click', async () => {
        try {
            await fetch('/auth/logout', { method: 'POST' });
        } finally {
            window.location.reload();
        }
    });
}

let confirmedForms = new WeakSet();
document.querySelectorAll('[data-confirm]').forEach($form => {
    $form.addEventListener('submit', async event => {
        if (confirmedForms.has($form)) {
            return;
        }
        event.preventDefault();
        let expectedInput = $form.dataset.confirmInput;
        let result = await Swal.fire({
            title: $form.dataset.confirmTitle,
            text: $form.dataset.confirm,
            icon: $form.dataset.confirmIcon || 'warning',
            input: expectedInput === undefined ? undefined : 'text',
            inputLabel: expectedInput === undefined ? undefined : `Zur Bestätigung ${expectedInput} eingeben`,
            inputPlaceholder: expectedInput,
            inputValidator: value =>
                expectedInput !== undefined && value !== expectedInput
                    ? `Bitte exakt ${expectedInput} eingeben.`
                    : undefined,
            showCancelButton: true,
            confirmButtonText: $form.dataset.confirmButton || 'SICHER',
            cancelButtonText: 'Abbrechen',
            confirmButtonColor: $form.dataset.confirmIcon === 'error' ? '#b91c1c' : '#047857',
            cancelButtonColor: '#57534e',
            focusCancel: true,
            reverseButtons: true
        });
        if (result.isConfirmed) {
            if (expectedInput !== undefined) {
                let $confirmation = document.createElement('input');
                $confirmation.type = 'hidden';
                $confirmation.name = 'confirmation';
                $confirmation.value = String(result.value || '');
                $form.append($confirmation);
            }
            confirmedForms.add($form);
            if (event.submitter instanceof HTMLElement) {
                $form.requestSubmit(event.submitter);
                return;
            }
            $form.requestSubmit();
        }
    });
});

let $ingredientsPopover = document.querySelector('[data-hover-popover]');
if ($ingredientsPopover !== null) {
    let $activeIngredientsTrigger = null;
    let hideIngredientsTimeout = null;
    let hideIngredients = () => {
        window.clearTimeout(hideIngredientsTimeout);
        if ($activeIngredientsTrigger !== null) {
            $activeIngredientsTrigger.setAttribute('aria-expanded', 'false');
        }
        $activeIngredientsTrigger = null;
        $ingredientsPopover.classList.add('hidden');
        $ingredientsPopover.innerHTML = '';
    };
    let positionIngredients = () => {
        if ($activeIngredientsTrigger === null) {
            return;
        }
        let triggerBounds = $activeIngredientsTrigger.getBoundingClientRect();
        let popupBounds = $ingredientsPopover.getBoundingClientRect();
        let gap = 8;
        let left = Math.min(Math.max(gap, triggerBounds.left), window.innerWidth - popupBounds.width - gap);
        let top = triggerBounds.bottom + gap;
        if (top + popupBounds.height > window.innerHeight - gap) {
            top = Math.max(gap, triggerBounds.top - popupBounds.height - gap);
        }
        $ingredientsPopover.style.left = `${left}px`;
        $ingredientsPopover.style.top = `${top}px`;
    };
    let showIngredients = $trigger => {
        window.clearTimeout(hideIngredientsTimeout);
        let $template = $trigger.parentElement.querySelector('[data-hover-template]');
        if ($template === null) {
            return;
        }
        if ($activeIngredientsTrigger !== null && $activeIngredientsTrigger !== $trigger) {
            $activeIngredientsTrigger.setAttribute('aria-expanded', 'false');
        }
        $activeIngredientsTrigger = $trigger;
        $activeIngredientsTrigger.setAttribute('aria-expanded', 'true');
        $ingredientsPopover.innerHTML = $template.innerHTML;
        $ingredientsPopover.classList.toggle(
            'w-[min(22rem,calc(100vw-1rem))]',
            $trigger.dataset.popoverSize === 'small'
        );
        $ingredientsPopover.classList.toggle(
            'w-[min(38rem,calc(100vw-1rem))]',
            $trigger.dataset.popoverSize !== 'small'
        );
        $ingredientsPopover.classList.remove('hidden');
        createIcons({ icons, root: $ingredientsPopover });
        positionIngredients();
    };
    let scheduleIngredientsHide = () => {
        window.clearTimeout(hideIngredientsTimeout);
        hideIngredientsTimeout = window.setTimeout(() => {
            if ($activeIngredientsTrigger?.matches(':hover, :focus-visible') || $ingredientsPopover.matches(':hover')) {
                return;
            }
            hideIngredients();
        }, 180);
    };
    document.addEventListener('pointerover', event => {
        let $trigger = event.target.closest?.('[data-hover-trigger]');
        if ($trigger !== null && $trigger !== undefined) {
            showIngredients($trigger);
        }
    });
    document.addEventListener('pointerout', event => {
        let $trigger = event.target.closest?.('[data-hover-trigger]');
        if ($trigger === null || $trigger === undefined || $trigger.contains(event.relatedTarget)) {
            return;
        }
        scheduleIngredientsHide();
    });
    document.addEventListener('focusin', event => {
        let $trigger = event.target.closest?.('[data-hover-trigger]');
        if ($trigger !== null && $trigger !== undefined) {
            showIngredients($trigger);
        }
    });
    document.addEventListener('focusout', event => {
        let $trigger = event.target.closest?.('[data-hover-trigger]');
        if ($trigger !== null && $trigger !== undefined && !$ingredientsPopover.contains(event.relatedTarget)) {
            scheduleIngredientsHide();
        }
    });
    document.addEventListener('click', event => {
        let $trigger = event.target.closest?.('[data-hover-trigger]');
        if ($trigger !== null && $trigger !== undefined) {
            event.preventDefault();
            showIngredients($trigger);
            return;
        }
        if (!$ingredientsPopover.contains(event.target)) {
            hideIngredients();
        }
    });
    $ingredientsPopover.addEventListener('pointerenter', () => window.clearTimeout(hideIngredientsTimeout));
    $ingredientsPopover.addEventListener('pointerleave', scheduleIngredientsHide);
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            hideIngredients();
        }
    });
    window.addEventListener('resize', positionIngredients);
    window.addEventListener('scroll', hideIngredients, { passive: true });
}

document.addEventListener('click', async event => {
    let $button = event.target.closest?.('[data-rating-button]');
    if ($button === null || $button === undefined) {
        return;
    }
    let $article = $button.closest('[data-recipe-id]');
    let $picker = $button.closest('[data-rating-picker]');
    let $buttons = [...$picker.querySelectorAll('[data-rating-button]')];
    let rating = Number($button.dataset.rating);
    $buttons.forEach($ratingButton => ($ratingButton.disabled = true));
    let formData = new FormData();
    formData.set('csrf', document.body.dataset.csrf || '');
    formData.set('action', 'rate');
    formData.set('recipe_id', $article.dataset.recipeId);
    formData.set('rating', String(rating));
    try {
        let response = await fetch('/feedback', { method: 'POST', body: formData });
        let result = await response.json();
        if (!response.ok) {
            throw new Error(result.error || 'Die Bewertung konnte nicht gespeichert werden.');
        }
        $buttons.forEach($ratingButton => {
            let $star = $ratingButton.querySelector('svg');
            let selected = Number($ratingButton.dataset.rating) <= result.rating;
            $star.classList.toggle('fill-current', selected);
            $star.classList.toggle('text-amber-500', selected);
            $star.classList.toggle('text-stone-300', !selected);
        });
        let $summary = $article.querySelector('[data-rating-summary]');
        $summary.innerHTML = result.summary_html;
        createIcons({ icons, root: $summary });
    } catch (error) {
        showError(error instanceof Error ? error.message : 'Die Bewertung konnte nicht gespeichert werden.');
    } finally {
        $buttons.forEach($ratingButton => ($ratingButton.disabled = false));
    }
});

let $noteDialog = document.querySelector('[data-note-dialog]');
if ($noteDialog !== null) {
    let $noteForm = $noteDialog.querySelector('[data-note-form]');
    let $noteTitle = $noteDialog.querySelector('[data-note-title]');
    let $noteInput = $noteForm.querySelector('[name="note"]');
    let $noteRecipeId = $noteForm.querySelector('[name="recipe_id"]');
    let $noteError = $noteDialog.querySelector('[data-note-error]');
    let $activeNoteButton = null;
    document.addEventListener('click', event => {
        let $button = event.target.closest?.('[data-note-button]');
        if ($button === null || $button === undefined) {
            return;
        }
        let $article = $button.closest('[data-recipe-id]');
        let $template = $button.parentElement.querySelector('[data-note-template]');
        $activeNoteButton = $button;
        $noteRecipeId.value = $article.dataset.recipeId;
        $noteTitle.textContent = $article.querySelector('h2').textContent;
        $noteInput.value = $template.content.textContent;
        $noteError.classList.add('hidden');
        $noteDialog.showModal();
        $noteInput.focus();
    });
    $noteDialog.querySelectorAll('[data-note-close]').forEach($button => {
        $button.addEventListener('click', () => $noteDialog.close());
    });
    $noteForm.addEventListener('submit', async event => {
        event.preventDefault();
        let $submit = $noteForm.querySelector('[type="submit"]');
        $submit.disabled = true;
        $noteError.classList.add('hidden');
        let formData = new FormData();
        formData.set('csrf', document.body.dataset.csrf || '');
        formData.set('action', 'note');
        formData.set('recipe_id', $noteRecipeId.value);
        formData.set('note', $noteInput.value);
        try {
            let response = await fetch('/feedback', { method: 'POST', body: formData });
            let result = await response.json();
            if (!response.ok) {
                throw new Error(result.error || 'Die Notiz konnte nicht gespeichert werden.');
            }
            let $template = $activeNoteButton.parentElement.querySelector('[data-note-template]');
            $template.content.textContent = result.note;
            let hasNote = result.note !== '';
            $activeNoteButton.classList.toggle('text-emerald-700', hasNote);
            $activeNoteButton.classList.toggle('hover:text-emerald-900', hasNote);
            $activeNoteButton.classList.toggle('text-stone-400', !hasNote);
            $activeNoteButton.classList.toggle('hover:text-stone-700', !hasNote);
            $activeNoteButton.title = hasNote ? 'Notiz bearbeiten' : 'Notiz hinzufügen';
            $activeNoteButton.setAttribute('aria-label', $activeNoteButton.title);
            $noteDialog.close();
        } catch (error) {
            $noteError.textContent =
                error instanceof Error ? error.message : 'Die Notiz konnte nicht gespeichert werden.';
            $noteError.classList.remove('hidden');
        } finally {
            $submit.disabled = false;
        }
    });
}

let $taskForm = document.querySelector('[data-task-form]');
if ($taskForm !== null) {
    let $panel = document.querySelector('[data-task-panel]');
    let $icon = document.querySelector('[data-task-icon]');
    let $progress = document.querySelector('[data-task-progress]');
    let $percentage = document.querySelector('[data-task-percentage]');
    let $time = document.querySelector('[data-task-time]');
    let $status = document.querySelector('[data-task-status]');
    let $reweHelp = document.querySelector('[data-task-rewe-help]');
    let $stop = document.querySelector('[data-task-stop]');
    let $basket = document.querySelector('[data-task-basket]');
    let $return = document.querySelector('[data-task-return]');
    let controller = new AbortController();
    let startedAt = performance.now();
    let currentProgress = 1;
    let sampledProgress = 0;
    let sampledAt = startedAt;
    let progressSamples = [];
    let cancellationSent = false;
    let terminal = false;
    let formatDuration = seconds => {
        let roundedSeconds = Math.max(0, Math.round(seconds));
        let hours = Math.floor(roundedSeconds / 3600);
        let minutes = Math.floor((roundedSeconds % 3600) / 60);
        let remainingSeconds = roundedSeconds % 60;
        if (hours > 0) {
            return `${hours}:${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`;
        }
        return `${minutes}:${String(remainingSeconds).padStart(2, '0')}`;
    };
    let updateTime = () => {
        let elapsed = (performance.now() - startedAt) / 1000;
        if (terminal) {
            $time.textContent = `${formatDuration(elapsed)} vergangen`;
            return;
        }
        if (progressSamples.length === 0) {
            $time.textContent = `${formatDuration(elapsed)} vergangen · Restzeit wird berechnet`;
            return;
        }
        let sortedSamples = [...progressSamples].sort((first, second) => first - second);
        let secondsPerPercent = sortedSamples[Math.floor(sortedSamples.length / 2)];
        let remaining = secondsPerPercent * (100 - currentProgress);
        $time.textContent = `${formatDuration(elapsed)} vergangen · Restzeit ca. ${formatDuration(remaining)}`;
    };
    let timeInterval = window.setInterval(updateTime, 1000);
    let applyUpdate = update => {
        let nextProgress = Math.max(1, Math.min(100, Number(update.progress) || 1));
        let now = performance.now();
        if (sampledProgress === 0) {
            sampledProgress = nextProgress;
            sampledAt = now;
        } else if (nextProgress > sampledProgress) {
            progressSamples.push((now - sampledAt) / 1000 / (nextProgress - sampledProgress));
            progressSamples = progressSamples.slice(-3);
            sampledProgress = nextProgress;
            sampledAt = now;
        }
        currentProgress = nextProgress;
        $progress.style.width = `${currentProgress}%`;
        $percentage.textContent = `${currentProgress} %`;
        $status.textContent = update.message || 'Vorgang läuft.';
        $reweHelp.classList.toggle('hidden', update.help !== 'rewe-cookie-export');
        if (!['success', 'error', 'cancelled'].includes(update.type)) {
            updateTime();
            return;
        }
        terminal = true;
        window.clearInterval(timeInterval);
        updateTime();
        $status.classList.remove('line-clamp-2', 'h-10');
        $status.classList.add('min-h-10');
        let success = update.type === 'success';
        let cancelled = update.type === 'cancelled';
        $panel.classList.toggle('border-red-200', !success && !cancelled);
        $panel.classList.toggle('border-amber-200', cancelled);
        $icon.className = success
            ? 'grid size-11 shrink-0 place-items-center rounded-md bg-emerald-50 text-emerald-700'
            : cancelled
              ? 'grid size-11 shrink-0 place-items-center rounded-md bg-amber-50 text-amber-700'
              : 'grid size-11 shrink-0 place-items-center rounded-md bg-red-50 text-red-700';
        $icon.innerHTML = `<i data-lucide="${success ? 'circle-check' : cancelled ? 'circle-stop' : 'circle-x'}" class="size-5"></i>`;
        $progress.className = success
            ? 'h-full rounded-full bg-emerald-700 transition-[width] duration-300'
            : cancelled
              ? 'h-full rounded-full bg-amber-600 transition-[width] duration-300'
              : 'h-full rounded-full bg-red-700 transition-[width] duration-300';
        $stop.classList.add('hidden');
        if ($basket !== null && success) {
            $basket.classList.remove('hidden');
            $basket.classList.add('flex');
        }
        $return.href = update.return_url || $return.href;
        $return.classList.remove('hidden');
        $return.classList.add('flex');
        createIcons({ icons });
    };
    let requestCancellation = async showState => {
        if (terminal || cancellationSent) {
            return;
        }
        cancellationSent = true;
        try {
            let response = await fetch('/task/cancel', {
                method: 'POST',
                body: new FormData($taskForm)
            });
            if (!response.ok) {
                throw new Error('Der Abbruch konnte nicht bestätigt werden.');
            }
            if (showState && !terminal) {
                applyUpdate({ type: 'cancelled', progress: currentProgress, message: 'Vorgang wurde gestoppt.' });
            }
        } catch (error) {
            if (showState) {
                showError(error instanceof Error ? error.message : 'Der Vorgang konnte nicht gestoppt werden.');
            }
            cancellationSent = false;
            return;
        }
        controller.abort();
    };
    $stop.addEventListener('click', () => requestCancellation(true));
    window.addEventListener('pagehide', () => {
        if (!terminal) {
            navigator.sendBeacon('/task/cancel', new FormData($taskForm));
        }
    });
    let runTask = async () => {
        try {
            let response = await fetch('/task', {
                method: 'POST',
                body: new FormData($taskForm),
                signal: controller.signal
            });
            if (response.body === null) {
                throw new Error('Der Fortschritt konnte nicht gelesen werden.');
            }
            let reader = response.body.getReader();
            let decoder = new TextDecoder();
            let buffer = '';
            let parseProgress = line => {
                let trimmedLine = line.trim();
                if (!trimmedLine.startsWith('data:')) {
                    return;
                }
                let payload = trimmedLine.slice(5).trimStart();
                applyUpdate(JSON.parse(payload));
            };
            while (true) {
                let result = await reader.read();
                buffer += decoder.decode(result.value || new Uint8Array(), { stream: !result.done });
                let lines = buffer.split('\n');
                buffer = lines.pop() || '';
                lines.forEach(parseProgress);
                if (result.done) {
                    break;
                }
            }
            if (buffer.trim() !== '') {
                parseProgress(buffer);
            }
            if (!terminal) {
                throw new Error('Die Verbindung wurde vor Abschluss beendet.');
            }
            if (!response.ok && !terminal) {
                throw new Error('Der Vorgang konnte nicht abgeschlossen werden.');
            }
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }
            applyUpdate({
                type: 'error',
                progress: 100,
                message: error instanceof Error ? error.message : 'Der Vorgang ist fehlgeschlagen.'
            });
        }
    };
    runTask();
}

window.addEventListener('pageshow', event => {
    if (event.persisted && document.querySelector('[data-recipe-grid]') !== null) {
        window.location.reload();
    }
});

let $recipeGrid = document.querySelector('[data-recipe-grid]');
let $lazyLoader = document.querySelector('[data-lazy-loader]');
if ($recipeGrid !== null && $lazyLoader !== null) {
    let loading = false;
    let observer = new IntersectionObserver(
        async entries => {
            if (!entries.some(entry => entry.isIntersecting) || loading) {
                return;
            }
            loading = true;
            try {
                let url = new URL(window.location.href);
                url.searchParams.set('partial', '1');
                url.searchParams.set('page', $lazyLoader.dataset.nextPage);
                let response = await fetch(url);
                if (!response.ok) {
                    throw new Error('Weitere Rezepte konnten nicht geladen werden.');
                }
                let result = await response.json();
                $recipeGrid.insertAdjacentHTML('beforeend', result.html);
                createIcons({ icons });
                if (result.has_more !== true) {
                    observer.disconnect();
                    $lazyLoader.remove();
                    return;
                }
                $lazyLoader.dataset.nextPage = String(result.next_page);
            } catch (error) {
                observer.disconnect();
                $lazyLoader.textContent =
                    error instanceof Error ? error.message : 'Weitere Rezepte konnten nicht geladen werden.';
            } finally {
                loading = false;
            }
        },
        { rootMargin: '800px 0px' }
    );
    observer.observe($lazyLoader);
}
