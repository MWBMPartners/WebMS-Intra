# Mobile audit worksheet (#225)

This worksheet structures the device walk-through that the static
`tools/audit-checks/check_mobile_readiness.py` audit can't cover —
touch behaviour, file pickers, autofill, OS keyboards, network drop.

Repeat the walk-through before each major release. Findings → child
issues with the `scope: ui` + `app:<slug>` labels.

## Devices

Tick what you tested with:

- [ ] iPhone (model + iOS version: ____________________)
- [ ] Android (model + Android version: ____________________)
- [ ] Tablet (model + OS: ____________________)
- [ ] On 3G / slow-network throttling

For each flow below, walk through it on each device, ticking the
dimensions that pass and flagging in the **Findings** column.

## Dimensions checklist (apply to every flow)

| Code | Dimension | Pass criterion |
|---|---|---|
| **TT** | Tap targets ≥ 44 px | All buttons, links, checkboxes hit-tested |
| **NH** | No horizontal scroll | Page width fits viewport, no sideways drag |
| **MR** | Modals reachable | Close button visible, body scrolls inside the dialog |
| **FF** | Form focus | Keyboard appears, doesn't obscure the field, no zoom-on-focus surprise |
| **FU** | File upload from camera roll | iOS picker shows Photo Library; gallery selectable |
| **LS** | Loading states | Spinner / disabled-button feedback on slow network |
| **OF** | Offline behaviour | PWA cache serves; no white-screen-of-death |

---

## 1. Sign-in (local + SSO + passkey)

URL: `/auth/login`

| Device | TT | NH | MR | FF | FU | LS | OF | Findings |
|---|---|---|---|---|---|---|---|---|
| iOS | ☐ | ☐ | — | ☐ | — | ☐ | ☐ | |
| Android | ☐ | ☐ | — | ☐ | — | ☐ | ☐ | |

Specific items to verify:
- Email autofill from password manager works.
- Passkey / WebAuthn prompt renders as native sheet.
- MS365 / Google redirect returns cleanly with the right backTo.

## 2. Dashboard

URL: `/`

| Device | TT | NH | MR | FF | FU | LS | OF | Findings |
|---|---|---|---|---|---|---|---|---|
| iOS | ☐ | ☐ | — | — | — | ☐ | ☐ | |
| Android | ☐ | ☐ | — | — | — | ☐ | ☐ | |

Specific items to verify:
- App cards reflow to a single column on small screens.
- Pinned announcements readable without horizontal scroll.
- "Open" button on cards isn't truncated by long app names.

## 3. Calendar

URL: `/calendar`

| Device | TT | NH | MR | FF | FU | LS | OF | Findings |
|---|---|---|---|---|---|---|---|---|
| iOS | ☐ | ☐ | ☐ | ☐ | — | ☐ | ☐ | |
| Android | ☐ | ☐ | ☐ | ☐ | — | ☐ | ☐ | |

Specific items to verify:
- Month view: long event names don't overflow the day cell.
- Agenda view fits on a single phone screen without grid awkwardness.
- RSVP modal close button reachable; quantity stepper works on touch.
- Event-detail dates / times respect timezone setting.

## 4. Prayer requests

URLs: `/prayer-requests` + `/prayer-requests/anonymous` + moderator review

| Device | TT | NH | MR | FF | FU | LS | OF | Findings |
|---|---|---|---|---|---|---|---|---|
| iOS | ☐ | ☐ | — | ☐ | — | ☐ | ☐ | |
| Android | ☐ | ☐ | — | ☐ | — | ☐ | ☐ | |

Specific items to verify:
- Anonymous-submit captcha renders (Turnstile / reCAPTCHA / hCaptcha).
- Long-form textarea expands; submit button stays visible above keyboard.
- Moderator approve/reject buttons sized for thumb on phone.

## 5. Attendance

URL: `/attendance/record`

| Device | TT | NH | MR | FF | FU | LS | OF | Findings |
|---|---|---|---|---|---|---|---|---|
| iOS | ☐ | ☐ | — | ☐ | — | ☐ | ☐ | |
| Android | ☐ | ☐ | — | ☐ | — | ☐ | ☐ | |

Specific items to verify:
- Numeric headcount input shows the number pad, not the QWERTY keyboard.
- Service-type buttons readable on a narrow viewport.

## 6. Expenses

URLs: `/expenses/submit` + `/expenses/approve` + PDF download

| Device | TT | NH | MR | FF | FU | LS | OF | Findings |
|---|---|---|---|---|---|---|---|---|
| iOS | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | |
| Android | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | ☐ | |

Specific items to verify:
- Receipt-upload `<input type="file">` shows camera + photo roll.
- Multi-line item form scrolls properly on small screens.
- Approve modal: full content visible; treasury comment field above keyboard.
- PDF download opens in native viewer.

## 7. Documents

URLs: `/documents` + upload

| Device | TT | NH | MR | FF | FU | LS | OF | Findings |
|---|---|---|---|---|---|---|---|---|
| iOS | ☐ | ☐ | — | ☐ | ☐ | ☐ | ☐ | |
| Android | ☐ | ☐ | — | ☐ | ☐ | ☐ | ☐ | |

Specific items to verify:
- File picker accepts PDF + Office docs + images.
- Download fires the system save sheet.

## 8. Announcements

URL: `/announcements`

| Device | TT | NH | MR | FF | FU | LS | OF | Findings |
|---|---|---|---|---|---|---|---|---|
| iOS | ☐ | ☐ | — | — | — | ☐ | ☐ | |
| Android | ☐ | ☐ | — | — | — | ☐ | ☐ | |

Specific items to verify:
- Body text reflows; embedded images scale.
- Native share sheet appears when sharing.

## 9. Admin

URLs: `/admin/users` + `/admin/errors` + `/admin/settings`

| Device | TT | NH | MR | FF | FU | LS | OF | Findings |
|---|---|---|---|---|---|---|---|---|
| iOS | ☐ | ☐ | ☐ | ☐ | — | ☐ | — | |
| Android | ☐ | ☐ | ☐ | ☐ | — | ☐ | — | |

Lower priority but should still be functional. Tables of users / errors
will overflow on small screens — that's acceptable for admin (a tablet
or laptop is the normal context) provided the responsive wrappers
handle the scroll gracefully.

---

## Reporting findings

For each finding:

1. Capture a screenshot (iOS: hold Power + Volume Up; Android:
   Power + Volume Down).
2. Comment on issue #225 with the screenshot + the flow code + the
   dimension code + a one-line description.
3. For critical findings (login broken; can't submit anything on a
   phone), open a child issue with `priority: high` and `scope: ui`.

## Companion static check

Run `python3 tools/audit-checks/check_mobile_readiness.py` before each
device walk-through. It surfaces:

- Missing `<meta viewport>`.
- Hard-coded pixel widths > 320px.
- Bare `<table>` outside `.table-responsive`.
- `<input type="file">` without `accept=` / `capture=`.
- `modal-dialog` without `modal-fullscreen-sm-down`.

It can't tell you whether real touch / autofill / camera roll work —
hence this worksheet.
