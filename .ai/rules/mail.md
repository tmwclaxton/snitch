---
paths:
  - 'app/Mail/**'
  - 'app/Http/Controllers/Marketing/ContactController.php'
  - 'app/Http/Requests/Marketing/**'
  - 'resources/js/pages/marketing/Contact.vue'
  - 'config/mail.php'
  - 'config/services.php'
---

# Contact mail and Postal

## Addresses
Public support address is `hello@snitchsocial.net`. Contact form deliveries go to `snitch.contact_to` (`SNITCH_CONTACT_TO`, default `tmwclaxton@gmail.com`). From address is `mail.from.address` (`hello@snitchsocial.net`).

## Transport
Production uses `MAIL_MAILER=postal` with `PostalTransport` (HTTP API to `POSTAL_BASE_URL`, key `POSTAL_API_KEY`). Local/dev stays on `log` unless Postal is configured.

## DNS split
Outbound: Postal on `admin.grantgunner.org` (DKIM `postal-vaOmeI._domainkey`, return-path `psrp` -> `rp.postal.grantgunner.org`, SPF includes `spf.postal.grantgunner.org`). Inbound: Cloudflare Email Routing MX forwards `hello@snitchsocial.net` to Gmail. SPF must include both `spf.postal.grantgunner.org` and `_spf.mx.cloudflare.net`. Tunnel ingress must keep `postal.grantgunner.org` -> `http://localhost:5000`.
