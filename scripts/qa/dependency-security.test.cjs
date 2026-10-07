const assert = require('node:assert/strict');
const { test } = require('node:test');
const { spawnSync } = require('node:child_process');
const { createRequire } = require('node:module');

const fromConcurrently = createRequire(require.resolve('concurrently'));
const shellQuote = fromConcurrently('shell-quote');

test('concurrently shell quoting rejects line terminators after comments', () => {
    for (const separator of ['\n', '\r', '\u2028', '\u2029']) {
        assert.throws(() => shellQuote.quote([{ comment: 'QA_RUN_security' }, `${separator}echo unsafe`]));
    }
});

test('concurrently retains quoted arguments, names and explicit colors', () => {
    const value = 'QA_RUN_security spaced argument';
    assert.deepEqual(shellQuote.parse(shellQuote.quote([value])), [value]);
    const result = spawnSync(process.execPath, [
        'node_modules/concurrently/dist/bin/concurrently.js',
        '-c', '#93c5fd,#c4b5fd', '--names=server,queue',
        '--passthrough-arguments',
        'node -e \'console.log(process.argv[1])\' {@}',
        'node -e \'console.log("QA_RUN_security_queue")\'',
        '--', value,
    ], { encoding: 'utf8', timeout: 10_000 });
    assert.equal(result.error, undefined);
    assert.equal(result.status, 0, result.stdout + result.stderr);
    assert.match(result.stdout, /\[server\] QA_RUN_security spaced argument/);
    assert.match(result.stdout, /\[queue\] QA_RUN_security_queue/);
});

test('concurrently kill-others terminates a long-running peer on failure', () => {
    const result = spawnSync(process.execPath, [
        'node_modules/concurrently/dist/bin/concurrently.js',
        '--names=server,queue', '--kill-others',
        'node -e \'setTimeout(() => process.exit(7), 200)\'',
        'node -e \'setInterval(() => {}, 1000)\'',
    ], { encoding: 'utf8', timeout: 10_000 });
    assert.equal(result.error, undefined);
    assert.equal(result.status, 1);
    assert.match(result.stdout, /SIGTERM/);
});
