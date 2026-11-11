# AAtoSMS

A NetSapiens **Web Responder** that texts a caller the message of an auto attendant.

A caller presses a key in the auto attendant and is sent to this application. It reads
the script text of one of the auto attendant's messages (key 2 by default) through the
NetSapiens API, asks the caller which mobile number to use, and sends that text as an SMS.

Plain PHP: no Composer, no database, no build step. Copying the folder is the install.

## What the caller hears

```
"To receive a text message at the number you are calling from, <caller's number>, press 1.
 To enter a different mobile number, press 2."
   |
   +-- 1 --------------------------------------------> text is sent to the caller's number
   |
   +-- 2 --> "Please enter the ten digit mobile number, starting with the area code."
                |
                +--> "You entered <that number>. To send the text message to this number,
                      press 1. To enter a different number, press 2."
                        |
                        +-- 1 ------------------------> text is sent to the entered number
                        +-- 2 ------------------------> back to "Please enter ..."

"Your text message has been sent. Thank you for calling. Goodbye."     (call is hung up)
```

- Numbers are read back digit by digit ("eight, four, five, ...").
- A typed number may start with 1 (eleven keys instead of ten) and may be ended with the
  pound key. Both are understood.
- No answer or a wrong key repeats the prompt. After three tries in a row (`MAX_ATTEMPTS`)
  the caller hears a goodbye and the call ends without a text.
- A caller without a usable caller ID (blocked, or an internal extension) is asked for a
  number right away.
- If anything is wrong (API down, no text on key 2, bad settings) the caller hears a short
  apology, the call is hung up, and the reason is written to the log.
- One call sends at most one text. A request that the PBX repeats never sends it again.

## Where the values come from

| Value | Taken from (first one that is not blank) |
| --- | --- |
| Number the text goes **to** | `NmsAni` (press 1), or the number the caller types (press 2) |
| Number the text is sent **from** | `SMSNumber` in the To-Web URL, else `NmsDnis` |
| Domain | `AccountDomain`, else `NS_DEFAULT_DOMAIN` in `.env` |
| Auto attendant extension | `AAUser` in the URL, else `AccountUser` |
| Prompt name of the auto attendant | `AAPrompt` in the URL, else the rule below |
| Key whose message is the text | `SMSOption` in the URL, else `SMS_SCRIPT_OPTION` in `.env` (default `2`) |
| User the text is sent as | `SMSUser` in the URL, else `SMS_USER` in `.env`, else the auto attendant extension |

Prompt name rule:

| Auto attendant extension | Prompt name | Example |
| --- | --- | --- |
| 3 digits | `Prompt_` + extension + `001` | `123` -> `Prompt_123001` |
| 4 digits | `Prompt_` + extension + `01` | `8002` -> `Prompt_800201`, `9000` -> `Prompt_900001` |

Any other extension, or an auto attendant that does not follow the rule (a second time
frame, for example), needs `AAPrompt=<prompt name>` in the To-Web URL.

The two API calls, with `NS_API_TOKEN` as bearer token:

```
GET  {NS_API_URL}/domains/{domain}/users/{auto attendant}/autoattendants/{prompt}
       text = "auto-attendant" > "option-2" > "audio" > "file-script-text"

POST {NS_API_URL}/domains/{domain}/users/{sms user}/messages
       {"type":"sms","message":"<text>","destination":"18452632561","from-number":"18457747000"}
```

## Requirements

- PHP 8.2 or newer with the **cURL** extension. Written for PHP 8.5; the tests run on
  PHP 8.2 to 8.5, on Windows and Linux.
- A web server that runs PHP and that the NetSapiens core can reach, preferably over HTTPS.
- NetSapiens v44.1 or newer with Voice Services (text-to-speech) enabled, because the
  prompts use the `<Say>` verb.
- An API token that may read the auto attendant and send SMS for the user.
- An SMS-enabled number in the domain to send from.

## Install on Windows

The examples use `D:\www\aatosms`. Any folder works; see [Moving the folder](#moving-the-folder).

### 1. Copy the project

Copy the whole project folder to the server, for example to `D:\www\aatosms`.

### 2. Create the settings file

```bat
cd /d D:\www\aatosms
copy .env.example .env
php -r "echo bin2hex(random_bytes(24));"
notepad .env
```

Fill in the three required values:

| Setting | Value |
| --- | --- |
| `NS_API_URL` | Address of the API, e.g. `https://nms1.nyc.birns.net/ns-api/v2` |
| `NS_API_TOKEN` | The API token (bearer token) |
| `WEBRESPONDER_KEY` | The random value printed by the `php -r` command above. It is the password of this application; the To-Web URL has to carry it. |

Everything else has a working default. `.env.example` explains every setting.

### 3. Check the installation

```bat
php bin\check.php
php bin\check.php AccountDomain=1180.DynaCloud AccountUser=9000 SMSNumber=8457747000
```

The first command checks PHP, the settings and the storage folder. The second one also
reads the auto attendant and shows the text that would be sent - without sending anything.
To send one real text as a test, add `SendTo=<your mobile number>`.

A server can hold more than one PHP. `php` is the first one Windows finds (`where php`
lists them), which is not always the one the web server uses. The report names the
program it ran with. To use another one, write its full path - with XAMPP:

```bat
C:\xampp\php\php.exe bin\check.php
```

### 4. Web server

**Serve only the `public` folder.** It contains the single file the PBX talks to
(`index.php`). The rest of the project - above all `.env` with the API token, and `var`
with logs and call data - must not be reachable from the web.

IIS: add a virtual directory to the site (IIS Manager > the site > *Add Virtual Directory*).

| Field | Value |
| --- | --- |
| Alias | `aatosms` |
| Physical path | `D:\www\aatosms\public` |

Apache: add to the configuration of the site, then restart Apache.

```apache
Alias /aatosms "D:/www/aatosms/public"
<Directory "D:/www/aatosms/public">
    Require all granted
</Directory>
```

Either way the address becomes `https://your-server/aatosms/index.php`.

If `D:\www` is itself the root of the web site, the project can already be reached as
`https://your-server/aatosms/public/index.php`. That works, but the whole project folder
is then inside the web site and the private files are protected only by the safety nets
described under [Security](#security). Adding the virtual directory or alias above is
still the better setup: it takes precedence over the folder of the same name, so only
`public` remains reachable.

The Windows account that runs PHP for the web site needs **Modify** permission on the
`var` folder and read permission on the rest. On IIS that account is usually `IUSR`
(otherwise the identity of the application pool):

```bat
icacls "D:\www\aatosms\var" /grant "IUSR:(OI)(CI)M"
```

Step 5 tells you whether the permission is right.

### 5. Check it through the web server

Open this address in a browser (with your own key):

```
https://your-server/aatosms/index.php?key=YOUR_WEBRESPONDER_KEY&check=1&AccountDomain=1180.DynaCloud&AccountUser=9000&SMSNumber=8457747000
```

It shows the same report as step 3, but produced by the PHP of the web server, which
often has another `php.ini` and runs under another Windows account. Fix everything
marked `[FAIL]`. This page never sends a text.

### Moving the folder

Nothing in the application depends on where it is. To move it, for example from
`D:\www\aatosms` to `C:\aatosms`:

1. Move the whole folder, including `.env` and `var`.
2. Point the web server at the new `public` folder (IIS: physical path of the virtual
   directory; Apache: the `Alias` and `<Directory>` lines).
3. Give the web server account Modify permission on the new `var` folder.
4. Repeat steps 3 and 5 of the installation.

The address does not change, so nothing has to be changed on the PBX. Only if you put
absolute paths in `.env` (`STORAGE_PATH`, `NS_API_CA_FILE`) do they need a look.

## NetSapiens setup

1. **Auto attendant.** Put the text on key 2 as a message made with text-to-speech: its
   script is the text of the SMS. Callers who press 2 hear it; this application sends the
   same text. Point another key at the Web Responder destination created in the next step.
2. **Dial translation.** Create one with the application **To-Web** and put the address
   in the *Application Parameter* field:

   ```
   https://your-server/aatosms/index.php?key=YOUR_WEBRESPONDER_KEY&SMSNumber=8457747000
   ```

3. **Make a test call** and press the key.

The core adds its own values to every request (`NmsAni`, `NmsDnis`, `AccountUser`,
`AccountDomain`, `Digits` ...). GET is the default; adding `<HttpMethod=POST>` to the
Application Parameter also works. Parameter names are not case-sensitive.

`AccountUser` has to be the extension of the auto attendant, which is the case when the
auto attendant itself forwards the call. If your routing makes another user the owner of
the call, add `AAUser=<auto attendant extension>` to the address.

Parameters you can add to the address:

| Parameter | Meaning | Example |
| --- | --- | --- |
| `key` | **Required.** The value of `WEBRESPONDER_KEY`. | |
| `SMSNumber` | Number the text is sent from. Blank or absent: the dialed number (`NmsDnis`). | `SMSNumber=8457747000` |
| `SMSOption` | Auto attendant key whose message is the text (`0`-`9` or `*`). | `SMSOption=5` |
| `SMSUser` | User the text is sent as: the user that owns the SMS number. | `SMSUser=7003` |
| `AAUser` | Auto attendant extension, when it is not `AccountUser`. | `AAUser=8002` |
| `AAPrompt` | Prompt name, when the rule does not fit. | `AAPrompt=Prompt_800202` |

One installation serves any number of auto attendants and domains: each dial translation
carries its own parameters.

## Settings

All settings are in `.env`; a change takes effect on the next call. The ones most likely
to be changed:

| Setting | Default | Meaning |
| --- | --- | --- |
| `SMS_SCRIPT_OPTION` | `2` | Auto attendant key whose message is the text |
| `SMS_USER` | blank | User the text is sent as; blank = the auto attendant extension |
| `SMS_NUMBER_FORMAT` | `11digit` | Numbers in the API request: `11digit` 18452632561, `10digit` 8452632561, `e164` +18452632561 |
| `SMS_LIMIT_PER_DESTINATION` | `3` | Most texts one number can receive per `SMS_LIMIT_WINDOW_MINUTES` (60); `0` = no limit |
| `CALLER_ID_DIGIT_STYLE` | `words` | How numbers are read back: `words`, `commas`, `hyphens` or `spaces` |
| `TTS_VOICE`, `TTS_LANGUAGE` | blank | Voice and language of the prompts; blank = the defaults of the PBX |
| `MAX_ATTEMPTS` | `3` | How often a prompt is played before the call ends |
| `APP_URL` | blank | Public address of the application: the folder with `index.php`, or `index.php` itself, as in the To-Web URL. Set it if the keys a caller presses seem to be ignored. |
| `NS_DEFAULT_DOMAIN` | blank | Domain to use when the PBX does not send `AccountDomain` |
| `LOG_LEVEL` | `info` | `debug` also logs every request and every XML answer |
| `PROMPT_...` | | The wording of every prompt; `{number}` is replaced by the phone number |

## Logs and troubleshooting

Logs are daily files in `var\logs` (`aatosms-2026-11-11.log`), kept for 30 days.
A normal call writes two lines:

```
2026-11-11T09:30:02-05:00 INFO    [4f9a2c1b] Call started {"domain":"1180.DynaCloud","aa_user":"9000","prompt":"Prompt_900001","option":"2","sms_user":"9000","from":"8457747000","caller":"8452632561",...}
2026-11-11T09:30:09-05:00 INFO    [4f9a2c1b] Text sent {"to":"18452632561","from":"18457747000",...,"http_status":200,"api_response":"{\"code\":202,\"message\":\"Accepted\"}",...}
```

The value in brackets identifies the call. The same value is in every XML answer
(`<!-- AAtoSMS 1.0.0 ref=4f9a2c1b -->`), so a call trace can be matched to its log lines.
The API token, the key and the call ids are never written to the log.

| What happens | Look for | Usual cause |
| --- | --- | --- |
| The call drops as soon as the key is pressed, nothing in the log | Web server access log; step 5 above | The core cannot reach the address, or PHP cannot write to `var` (then the lines are in the PHP error log) |
| The check says that the cURL extension is not loaded | The lines below that message | `extension=curl` is missing in `php.ini`, or PHP cannot load `php_curl.dll`. On Windows the usual cause is a mix of two PHP downloads: `php.exe` from the "Non Thread Safe" one and the `ext` folder from the "Thread Safe" one, or the other way round. `php -v` then starts with warnings that end in "These options need to match". The check names both builds and the PHP to use |
| Every address of the application gives HTTP 500 (IIS: 500.19) | The error page or the Apache error log names `web.config` or `.htaccess` | The web server does not accept a safety-net file of this project (see [Security](#security)). Delete it: `web.config` on IIS, the `.htaccess` files on Apache. They are not needed when only `public` is served |
| The call drops, or the caller hears "Access denied" | `Request rejected: the key in the URL is missing or wrong` | `key=` in the To-Web address differs from `WEBRESPONDER_KEY` |
| The caller hears the apology right away | `Call cannot start: ...` | The line names it: HTTP 401 = wrong or expired token; HTTP 404 = wrong domain, extension or prompt name; "no script text" = key 2 has no text; "SMSNumber ... not a valid 10-digit number" |
| The menu plays but keys do nothing | Only `Call started`, no later lines | The core does not call back. `APP_URL` blank: set it. `APP_URL` set: it is wrong (step 5 warns when its path does not fit) |
| The caller hears the apology after pressing 1 | `Text not sent: ...` with the answer of the API | The number is not an SMS number of that user: set `SMSUser`/`SMS_USER`, or try another `SMS_NUMBER_FORMAT` |
| "Your text message has been sent" but nothing arrives | `Text sent` with `http_status` 2xx | The API accepted the text; check the SMS number and carrier side in NetSapiens |
| "A text message was already sent to this number recently" | `limit for this destination reached` | `SMS_LIMIT_PER_DESTINATION`: that number already got its texts for this hour |
| `cURL error 60` | The `hint` at the end of the log line | PHP cannot verify the HTTPS certificate of the API server. Usually PHP has no CA certificates: download `cacert.pem` from https://curl.se/docs/caextract.html and name it in `NS_API_CA_FILE` (or in `curl.cainfo` in `php.ini`). The hint says so when instead the certificate has expired or does not match the host name in `NS_API_URL`. |
| `cURL error 6`, `7` or `28` | The `hint` at the end of the log line | The web server cannot reach the API: DNS, firewall, or a wrong `NS_API_URL` |

For a closer look set `LOG_LEVEL=debug`, make one call, and set it back.

## Security

- The address works only with the right `key`. Treat the To-Web address like a password,
  and use HTTPS so it is not sent in the clear. Web server access logs contain it too.
- If possible, let only the NetSapiens core servers reach the address (firewall).
- A caller can type any number, so the application limits how many texts one number can
  receive (`SMS_LIMIT_PER_DESTINATION`, default 3 per hour). Texts that could not be
  sent do not count.
- HTTPS certificates of the API server are always verified, and redirects are not
  followed, so the API token only ever goes to the server named in `NS_API_URL`.
- `.env` and `var` must not be reachable from the web: serve only `public`. As a safety
  net the project also contains `web.config` (IIS) and `.htaccess` files (Apache) that
  refuse the private parts if the whole folder is served by mistake. Do not rely on
  them. Test: `https://your-server/aatosms/.env` must give an error page.

## Tests

```bat
php tests\run.php
php tests\http.php
```

Both run on the computer itself in a few seconds: no PBX, no network, no real texts.

- `run.php` drives whole calls request by request against a stand-in for the API and
  checks the XML answers, the API requests and the log.
- `http.php` repeats the main calls over real HTTP, with real PHP web server processes:
  the application twice (so that requests really arrive at the same moment) and
  stand-ins for the API, one of them with HTTPS to check that certificates are verified.
  It starts them on this computer only (127.0.0.1) and stops them when it is done.

`.github/workflows/tests.yml` runs both on every push, on Windows and Linux with
PHP 8.2, 8.4 and 8.5.

## Project layout

```
public/index.php     the only file served to the PBX
src/                 the application (App, CallFlow, CallPlan, NetSapiens, AutoAttendant, ...)
bin/check.php        installation check for the command line
tests/               tests (run.php, http.php) and their stand-ins for the API
var/                 logs, call state and send records (created at run time, private)
.env.example         template of the settings file
```

## Limits

- Phone numbers are US/Canada numbers: 10 digits, or 11 digits starting with 1.
- The prompts are English text-to-speech. The wording can be changed in `.env`.
- The text is sent exactly as it is stored in the auto attendant; the application does
  not shorten or split it.
