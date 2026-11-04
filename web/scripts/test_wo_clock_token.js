/**
 * @file
 * The clock-in CSRF token must not get stuck on a bad value.
 *
 * Chris Palecki, 2026-11-03: two clock-in attempts eight seconds apart on a
 * sprinkler repair, both refused with "X-CSRF-Token request header is
 * invalid", three hours unrecorded, and "Bad response" on the phone. The route
 * and /session/token were both healthy — the bug was in the JS:
 *
 *     tokenPromise = fetch(...).then(r => r.text()).catch(() => '');
 *
 * Whatever came back was memoised for the life of the page, INCLUDING the ''
 * from the catch. One dropped token request on a weak signal — this is a
 * phone-first feature used in the field — therefore cached an empty token
 * permanently, and every later attempt posted it and was refused. Nothing
 * retried, because the bad value was already "the answer".
 *
 * This exercises the two repaired paths against a stubbed fetch, so the fix is
 * demonstrated rather than asserted. It mirrors the real functions in
 * js/wo-clock.js — if that file changes, change this with it.
 *
 * Run: ddev exec node web/scripts/test_wo_clock_token.js
 */

'use strict';

let pass = 0;
let fail = 0;
function ok(what, good, detail) {
  good ? pass++ : fail++;
  console.log(`  ${good ? 'PASS' : 'FAIL'}  ${what}${detail ? '  [' + detail + ']' : ''}`);
}

// --- stub environment --------------------------------------------------------
let tokenPromise = null;
let fetchLog = [];
let scenario = 'none';
const Drupal = { url: (p) => '/' + p };

function tokenFetchCount() {
  return fetchLog.filter((u) => u === '/session/token').length;
}

global.fetch = function (url, opts) {
  fetchLog.push(url);
  if (url === '/session/token') {
    if (scenario === 'tokenFailsFirst' && tokenFetchCount() === 1) {
      return Promise.reject(new Error('network dropped'));
    }
    if (scenario === 'tokenEmpty' && tokenFetchCount() === 1) {
      return Promise.resolve({ ok: true, text: () => Promise.resolve('   ') });
    }
    return Promise.resolve({ ok: true, text: () => Promise.resolve('GOOD' + tokenFetchCount()) });
  }
  const sent = opts.headers['X-CSRF-Token'];
  // The server refuses the first-issued token, as it would a stale one.
  if (scenario === 'serverRejects' && sent === 'GOOD1') {
    return Promise.resolve({ status: 403, json: () => Promise.reject(new Error('not json')) });
  }
  if (!sent) {
    return Promise.resolve({ status: 403, json: () => Promise.reject(new Error('not json')) });
  }
  return Promise.resolve({ status: 200, json: () => Promise.resolve({ status: 'ok', tokenSeen: sent }) });
};

// --- the functions under test (mirrors js/wo-clock.js) -----------------------
function sessionToken(force) {
  if (force) { tokenPromise = null; }
  if (!tokenPromise) {
    tokenPromise = fetch(Drupal.url('session/token'), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('token fetch ' + r.status); } return r.text(); })
      .then(function (t) { t = (t || '').trim(); if (!t) { throw new Error('empty token'); } return t; })
      .catch(function () { tokenPromise = null; return ''; });
  }
  return tokenPromise;
}

function post(url, body) {
  function attempt(force) {
    return sessionToken(force).then(function (token) {
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify(body || {}),
      }).then(function (r) {
        if (!force && (r.status === 403 || r.status === 401)) { return attempt(true); }
        return r.json().catch(function () { return { status: 'error', message: 'Bad response' }; });
      });
    });
  }
  return attempt(false);
}

function reset(s) { scenario = s; tokenPromise = null; fetchLog = []; }

// --- the cases ---------------------------------------------------------------
(async function () {
  // 1. A dropped token fetch must not poison the page.
  //
  // ⚠ This asserted "that attempt fails, the next one recovers" and the fix
  // turned out to do better: the empty token is refused with a 403, which
  // trips the retry, which fetches a good token — so the SAME click succeeds.
  // Chris's very first press would have worked. The assertion was encoding the
  // old behaviour, not a requirement.
  reset('tokenFailsFirst');
  const first = await post('/clock/in/1');
  ok('a dropped token fetch recovers within the same click', first.status === 'ok',
    first.status === 'ok' ? 'token ' + first.tokenSeen : JSON.stringify(first));
  scenario = 'none';
  const second = await post('/clock/in/1');
  ok('  and the page is not left poisoned afterwards', second.status === 'ok',
    second.status === 'ok' ? 'token ' + second.tokenSeen : 'still stuck — the original bug');

  // 2. An empty-bodied token response is treated as a failure, not a token.
  reset('tokenEmpty');
  await post('/clock/in/1');
  scenario = 'none';
  const afterEmpty = await post('/clock/in/1');
  ok('an empty token is never memoised', afterEmpty.status === 'ok',
    afterEmpty.status === 'ok' ? 'recovered' : 'empty token cached — the original bug');

  // 3. A 403 from the server refreshes the token and retries once.
  reset('serverRejects');
  const retried = await post('/clock/in/1');
  ok('a 403 triggers one retry with a fresh token', retried.status === 'ok',
    retried.status === 'ok' ? 'succeeded with ' + retried.tokenSeen : 'no retry');
  ok('  the retry used a DIFFERENT token', retried.tokenSeen && retried.tokenSeen !== 'GOOD1',
    String(retried.tokenSeen));
  ok('  exactly one extra token fetch', tokenFetchCount() === 2, tokenFetchCount() + ' fetches');

  // 4. The retry is capped — a genuinely refused token must not loop.
  reset('none');
  global.fetch = (function (inner) {
    return function (url, opts) {
      if (url !== '/session/token') {
        fetchLog.push(url);
        return Promise.resolve({ status: 403, json: () => Promise.reject(new Error('x')) });
      }
      return inner(url, opts);
    };
  })(global.fetch);
  const hopeless = await post('/clock/in/1');
  ok('a persistently refused token stops after one retry', hopeless.status === 'error',
    'posts attempted: ' + fetchLog.filter((u) => u !== '/session/token').length + ' (expect 2)');
  ok('  and it does not loop', fetchLog.filter((u) => u !== '/session/token').length === 2);

  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
