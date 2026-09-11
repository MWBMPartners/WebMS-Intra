# What DreamHost shared hosting actually allows (checked 11 September 2026)

These decide which publishing mechanisms are possible at all, so they belong in
the design rather than being assumed.

## 1. Reverse proxying is NOT available on shared hosting — RULED OUT

DreamHost's own Proxy Server feature (Apache `mod_proxy`) is offered **only on
Managed VPS and Dedicated plans**, not on shared hosting. So the main website
cannot forward `example.org/noticeboard` through to the portal at the web-server
level.

Worth noting separately, because people reach for it: Apache does not permit
`ProxyPass` in a `.htaccess` file under any configuration — it is only valid in
the server or virtual-host configuration, which a shared customer cannot edit.
The `[P]` flag on a rewrite rule is the only `.htaccess` route to proxying, and
it still needs `mod_proxy` to be loaded and permitted.

Source: https://help.dreamhost.com/hc/en-us/articles/217955787-Proxy-Server

## 2. Domains sit side by side under the home directory — CONDITIONALLY USEFUL

The layout is:

    /home/username/
        portal.example.org/     <- the portal (this repo deploys here)
        example.org/            <- the main website

One user may hold as many domains as they like, each with its own directory, on
the same filesystem.

**But this cannot be relied on.** DreamHost operates a "one user per domain"
policy, and a domain may be owned by a *different* user — in which case, in their
words, users "do not have access to the website files under any other user."

So sharing files or a database connection directly between the two directories
works **only if the customer happens to have put both domains under one user**,
which is a choice we neither control nor can detect remotely, and which
DreamHost's own guidance pushes against.

Sources:
- https://help.dreamhost.com/hc/en-us/articles/360001219231-Where-is-the-home-directory
- https://help.dreamhost.com/hc/en-us/articles/215562847-One-user-per-domain-policy

## 3. What this leaves

Any mechanism that must work for every customer regardless of how their hosting
is arranged has to travel **over HTTP** — a script the page loads, a frame, or a
data feed something else consumes.

Same-filesystem tricks and static publishing into the main domain's directory can
be offered as an OPTIONAL faster path where the customer's setup allows it, but
neither can be the primary design, and neither should be assumed to work.

## 4. Already known from the repo

- The deploy reaches three directories, all under the portal subdomain's base:
  `public_html/` (main), `public_html_beta/` (beta), `public_html_dev/` (alpha).
  The main domain is outside it entirely.
- The account already holds extra directories for other purposes
  (`public_html_landing/`, `public_html_redir/`), which the deploy excludes.
- DreamHost shared FastCGI will kill a long-running request (DEV_NOTES line 3616),
  so nothing here may hold a worker open — that rules out long-polling a foyer
  display and argues for short cached requests.
