import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import yaml from 'js-yaml';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');
const production = yaml.load(read('docker-compose.yml'));
const local = yaml.load(read('docker-compose.local.yml'));

test('production exposes FastCGI and Reverb only to the host proxy', () => {
    assert.deepEqual(production.services.app.ports, ['127.0.0.1:9000:9000', '127.0.0.1:6001:6001']);
    assert.equal(production.services.postgres.ports, undefined);
    assert.equal(production.services.redis.ports, undefined);
});

test('every local published port is loopback-only', () => {
    for (const service of Object.values(local.services)) {
        for (const port of service.ports ?? []) {
            assert.match(port, /^127\.0\.0\.1:\d+:\d+$/);
        }
    }
});

test('workers wait for prepared PHP-FPM and run without root', () => {
    for (const compose of [production, local]) {
        assert.equal(compose.services.app.healthcheck.test[1], 'php');
        for (const name of ['queue-worker', 'scheduler']) {
            assert.equal(compose.services[name].depends_on.app.condition, 'service_healthy');
            assert.equal(compose.services[name].user, 'www-data');
            assert.deepEqual(compose.services[name].volumes, compose.services.app.volumes);
        }
    }
});

test('production disables debug and local images explicitly retain test tools', () => {
    for (const name of ['app', 'queue-worker', 'scheduler']) {
        assert.equal(production.services[name].environment.APP_DEBUG, 'false');
        assert.equal(production.services[name].environment.APP_ENV, 'production');
        assert.equal(local.services[name].build.args.INSTALL_DEV_DEPENDENCIES, '1');
    }
});

test('Git excludes root and nested secrets, archives and private audit output', () => {
    const paths = ['.env', '.env.production', 'nested/.env', 'nested/auth.json', 'nested/secret.pem',
        'nested/secret.key', '_equitab_src_snapshot.tar.gz', 'nested/backup.zip', 'backup.sql',
        'database/database.sqlite', 'output/private-report.txt'];
    const ignored = execFileSync('git', ['check-ignore', '--no-index', '--stdin'], {
        cwd: root, input: paths.join('\n') + '\n', encoding: 'utf8',
    }).trim().split('\n');
    assert.deepEqual(ignored, paths);
    const visible = execFileSync('git', ['check-ignore', '--no-index', '--non-matching', '--verbose', '--stdin'], {
        cwd: root, input: '.env.example\ncomposer.lock\npackage-lock.json\nconfig/reverb.php\n', encoding: 'utf8',
    });
    assert.match(visible, /!\*\*\/\.env\.example\s+\.env\.example/);
    for (const file of ['composer.lock', 'package-lock.json', 'config/reverb.php']) {
        assert.ok(visible.includes(`::\t${file}`), file);
    }
});

test('shell-quote rejects the reported comment/newline injection without executing it', () => {
    const require = createRequire(import.meta.url);
    const concurrentlyRequire = createRequire(require.resolve('concurrently'));
    const quote = concurrentlyRequire('shell-quote').quote;
    assert.throws(() => quote(['echo', 'ok', { comment: 'x' }, 'a\nprintf NOT_EXECUTED;#']), TypeError);
    assert.equal(quote(['a b', 'c']), "'a b' c");
});

test('concurrently still expands ordinary arguments using the corrected quote library', () => {
    const result = execFileSync(process.execPath, [
        new URL('../node_modules/concurrently/dist/bin/concurrently.js', import.meta.url).pathname,
        '--raw', '--passthrough-arguments', 'node -e "process.stdout.write(process.argv[1])" -- {1}', '--', 'safe argument',
    ], { cwd: root, encoding: 'utf8', timeout: 15_000 });
    assert.equal(result, 'safe argument');
});

test('Reverb handshake, connection quota and message throttling use restrictive configuration', () => {
    const result = JSON.parse(execFileSync('php', ['scripts/test-reverb-configuration.php'], {
        cwd: root, encoding: 'utf8', timeout: 15_000,
    }));
    assert.equal(result.failed, 0);
    assert.equal(result.passed, 9);
});
