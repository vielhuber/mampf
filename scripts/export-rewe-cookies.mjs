import { spawn } from 'node:child_process';
import { access, chmod, copyFile, mkdir, rename, writeFile } from 'node:fs/promises';
import { createServer } from 'node:net';
import { dirname, resolve } from 'node:path';
import process from 'node:process';
import { createInterface } from 'node:readline/promises';
import { fileURLToPath } from 'node:url';

if (process.argv.includes('--help')) {
    console.log('Usage: npm run cookies:rewe');
    console.log('Opens a dedicated Chrome profile and exports all REWE cookies after confirmation.');
    process.exit(0);
}

let root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
let profileDirectory = resolve(root, '.data/rewe-chrome-profile');
let exportTargets = [
    { host: 'www.rewe.de', path: resolve(root, '.config/rewe-shop.json') },
    { host: 'account.rewe.de', path: resolve(root, '.config/rewe-account.json') }
];
let chromeCandidates = [
    process.env.CHROME_BIN,
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser'
].filter(Boolean);
let chromePath = null;

for (let candidate of chromeCandidates) {
    try {
        await access(candidate);
        chromePath = candidate;
        break;
    } catch {}
}

if (chromePath === null) {
    throw new Error('Kein Chrome oder Chromium gefunden. Setze bei Bedarf CHROME_BIN.');
}

let portServer = createServer();
await new Promise((resolvePromise, reject) => {
    portServer.once('error', reject);
    portServer.listen(0, '127.0.0.1', resolvePromise);
});
let portAddress = portServer.address();
if (portAddress === null || typeof portAddress === 'string') {
    throw new Error('Für Chrome konnte kein lokaler Debug-Port reserviert werden.');
}
let port = portAddress.port;
await new Promise((resolvePromise, reject) => portServer.close(error => (error ? reject(error) : resolvePromise())));

await mkdir(profileDirectory, { recursive: true, mode: 0o700 });
let chromeProcess = spawn(
    chromePath,
    [
        `--remote-debugging-port=${port}`,
        `--user-data-dir=${profileDirectory}`,
        '--no-first-run',
        '--no-default-browser-check',
        ...(process.getuid?.() === 0 ? ['--no-sandbox'] : []),
        'https://www.rewe.de/shop/checkout/basket',
        'https://account.rewe.de/realms/sso/account/'
    ],
    { stdio: 'ignore' }
);
let chromeExitCode = null;
let chromeStartError = null;
chromeProcess.once('exit', code => {
    chromeExitCode = code;
});
chromeProcess.once('error', error => {
    chromeStartError = error;
});
process.once('exit', () => {
    if (chromeProcess.exitCode === null && chromeProcess.signalCode === null) {
        chromeProcess.kill();
    }
});

let browserVersion = null;
for (let attempt = 0; attempt < 100; attempt += 1) {
    try {
        let response = await fetch(`http://127.0.0.1:${port}/json/version`);
        if (response.ok) {
            browserVersion = await response.json();
            break;
        }
    } catch {}
    if (chromeExitCode !== null) {
        break;
    }
    await new Promise(resolvePromise => setTimeout(resolvePromise, 200));
}

if (browserVersion?.webSocketDebuggerUrl === undefined) {
    throw new Error(
        chromeStartError?.message ??
            'Chrome konnte nicht gestartet werden. Schließe ein eventuell noch geöffnetes REWE-Exportfenster und versuche es erneut.'
    );
}

let input = createInterface({ input: process.stdin, output: process.stdout });
console.log('Chrome ist geöffnet. Löse dort die Menschprüfung, melde dich an und prüfe den Lieferstandort.');
await input.question('Drücke danach hier Enter, um alle REWE-Cookies zu exportieren: ');
input.close();

let socket = new WebSocket(browserVersion.webSocketDebuggerUrl);
await new Promise((resolvePromise, reject) => {
    socket.addEventListener('open', resolvePromise, { once: true });
    socket.addEventListener('error', () => reject(new Error('Die Chrome-Verbindung ist fehlgeschlagen.')), {
        once: true
    });
});

let cookieResponse = new Promise((resolvePromise, reject) => {
    let timeout = setTimeout(() => reject(new Error('Chrome hat beim Cookie-Export nicht geantwortet.')), 10000);
    socket.addEventListener('message', event => {
        let response = JSON.parse(event.data);
        if (response.id !== 1) {
            return;
        }
        clearTimeout(timeout);
        if (response.error !== undefined) {
            reject(new Error(response.error.message));
            return;
        }
        resolvePromise(response.result.cookies);
    });
});
socket.send(JSON.stringify({ id: 1, method: 'Storage.getCookies' }));
let cookies = await cookieResponse;

let cookieExports = exportTargets.map(exportTarget => {
    let matchingCookies = cookies
        .filter(cookie => {
            let domain = cookie.domain.replace(/^\./, '').toLowerCase();
            return exportTarget.host === domain || exportTarget.host.endsWith(`.${domain}`);
        })
        .map(cookie => ({
            name: cookie.name,
            value: cookie.value,
            domain: cookie.domain,
            path: cookie.path,
            secure: cookie.secure,
            httpOnly: cookie.httpOnly,
            expirationDate: cookie.expires > 0 ? cookie.expires : undefined
        }));

    if (matchingCookies.length === 0) {
        throw new Error(`Für ${exportTarget.host} wurden keine Cookies gefunden.`);
    }

    return { ...exportTarget, cookies: matchingCookies };
});

for (let cookieExport of cookieExports) {
    let exportTarget = cookieExport.path;
    await mkdir(dirname(exportTarget), { recursive: true });
    let targetExists = true;
    try {
        await access(exportTarget);
    } catch (error) {
        if (error.code !== 'ENOENT') {
            throw error;
        }
        targetExists = false;
    }
    if (targetExists) {
        await copyFile(exportTarget, `${exportTarget}.bak`);
        await chmod(`${exportTarget}.bak`, 0o600);
    }
    let temporaryPath = `${exportTarget}.tmp`;
    await writeFile(temporaryPath, `${JSON.stringify(cookieExport.cookies, null, 2)}\n`, { mode: 0o600 });
    await rename(temporaryPath, exportTarget);
    await chmod(exportTarget, 0o600);
    console.log(`${cookieExport.cookies.length} Cookies nach ${exportTarget} exportiert.`);
}

socket.send(JSON.stringify({ id: 2, method: 'Browser.close' }));
socket.close();
console.log('Fertig. Vorhandene Exporte wurden jeweils als .bak gesichert.');
