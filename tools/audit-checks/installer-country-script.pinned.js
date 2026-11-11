(function () {
var country = document.getElementById('country');
var zone = document.getElementById('timezone');
var money = document.getElementById('currency');
if (!country || !zone) { return; }
var browserZone = '';
try {
browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
} catch (e) {
browserZone = '';
}
function optionFor(value) {
for (var i = 0; i < zone.options.length; i++) {
if (zone.options[i].value === value) { return zone.options[i]; }
}
return null;
}
function zonesIn(code) {
var list = [];
for (var i = 0; i < zone.options.length; i++) {
if (zone.options[i].getAttribute('data-country') === code) { list.push(zone.options[i].value); }
}
return list;
}
function zoneFor(code) {
var list = zonesIn(code);
if (list.indexOf(browserZone) !== -1) { return browserZone; }
return list.length === 1 ? list[0] : '';
}
var browserOption = optionFor(browserZone);
if (country.getAttribute('data-chosen') !== '1' && browserOption) {
var code = browserOption.getAttribute('data-country') || '';
if (code !== '') { country.value = code; }
}
if (zone.getAttribute('data-chosen') !== '1' && country.value !== '') {
var suggested = zoneFor(country.value);
if (suggested !== '') { zone.value = suggested; }
}
function moneyFor() {
var chosen = country.options[country.selectedIndex];
return chosen ? (chosen.getAttribute('data-currency') || '') : '';
}
if (money && money.getAttribute('data-chosen') !== '1' && moneyFor() !== '') {
money.value = moneyFor();
}
country.addEventListener('change', function () {
if (country.value === '') { return; }
if (money && moneyFor() !== '') { money.value = moneyFor(); }
var shown = optionFor(zone.value);
if (shown && shown.getAttribute('data-country') === country.value) { return; }
zone.value = zoneFor(country.value);
});
}());
