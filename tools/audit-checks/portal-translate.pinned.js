(function () {
'use strict';
var DEADLINE_MS = 45000;
function say(box, words) {
var where = box.querySelector('[data-portal-translate-status]');
if (where !== null) {
where.textContent = words;
}
}
function outputBlock(box) {
return box.querySelector('[data-portal-translate-output]');
}
function label(button, name, fallback) {
var given = button.getAttribute(name);
return (given !== null && given !== '') ? given : fallback;
}
function shareToken(payload) {
if (payload === null || typeof payload !== 'object'
|| payload.meta === null || typeof payload.meta !== 'object'
|| typeof payload.meta.csrfToken !== 'string' || payload.meta.csrfToken === '') {
return false;
}
if (window.Portal === undefined || typeof window.Portal.writeCsrf !== 'function') {
return false;
}
window.Portal.writeCsrf(payload.meta.csrfToken);
return true;
}
function wireOne(box) {
var button = box.querySelector('[data-portal-translate-button]');
var output = outputBlock(box);
if (button === null || output === null) {
return;
}
var busy = false;
var fetched = false;
button.addEventListener('click', function () {
if (fetched === true) {
if (output.hasAttribute('hidden') === true) {
output.removeAttribute('hidden');
button.textContent = label(button, 'data-hide-label', 'Hide the translation');
} else {
output.setAttribute('hidden', 'hidden');
button.textContent = label(button, 'data-show-label', 'Show the translation');
}
return;
}
if (busy === true) {
return;
}
busy = true;
button.disabled = true;
say(box, 'Translating…');
var controller = new AbortController();
var deadline = window.setTimeout(function () {
controller.abort();
}, DEADLINE_MS);
var token = box.getAttribute('data-csrf') || '';
var payload = {
sourceTable: box.getAttribute('data-table') || '',
sourceID: box.getAttribute('data-id') || '',
targetLanguage: box.getAttribute('data-language') || '',
csrf_token: token
};
window.fetch(box.getAttribute('data-url') || '', {
method: 'POST',
credentials: 'same-origin',
signal: controller.signal,
headers: {
'Content-Type': 'application/json',
'X-CSRF-Token': token,
'Accept': 'application/json'
},
body: JSON.stringify(payload)
}).then(function (response) {
return response.json().then(function (body) {
return { ok: response.ok, status: response.status, body: body };
}, function () {
return { ok: false, status: response.status, body: null };
});
}).then(function (answer) {
window.clearTimeout(deadline);
var handedBack = shareToken(answer.body);
var body = answer.body;
if (answer.ok !== true || body === null || body.status !== 'ok') {
var said = (body !== null && typeof body.message === 'string') ? body.message : '';
if (answer.status === 403 && handedBack === false
&& (said === '' || said === 'CSRF check failed')) {
say(box, 'This page has been open a while. Please reload it and try again.');
return;
}
say(box, said !== '' ? said : 'The translation could not be made.');
return;
}
var data = body.data || {};
if (data.sameLanguage === true) {
say(box, typeof data.message === 'string'
? data.message
: 'This is already in your language.');
return;
}
output.textContent = String(data.translation || '');
output.removeAttribute('hidden');
fetched = true;
button.textContent = label(button, 'data-hide-label', 'Hide the translation');
say(box, 'Translated by machine. The original is above, unchanged.');
}).catch(function (problem) {
window.clearTimeout(deadline);
var lost = (problem !== null && problem.name === 'AbortError')
? 'No answer came back in time. '
: 'No answer came back. ';
say(box, lost + 'Reload the page and press Translate again — if the '
+ 'first attempt did get through, the second is free.');
}).then(function () {
busy = false;
button.disabled = false;
});
});
}
function wireAll() {
var boxes = document.querySelectorAll('[data-portal-translate]');
var index;
for (index = 0; index < boxes.length; index += 1) {
wireOne(boxes[index]);
}
}
if (document.readyState === 'loading') {
document.addEventListener('DOMContentLoaded', wireAll);
} else {
wireAll();
}
}());