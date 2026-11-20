# MailTask · Phase 1

This release validates Gmail access for one primary Google Workspace mailbox. The CLI test signs and exchanges an RS256 JWT, lists recent Inbox messages, and retrieves up to five messages. It prints counts and read/unread states only. It uses the Gmail readonly scope. Mail is handled in memory during the test. No mail is sent, changed, or stored.

## 1. Set up Google Cloud

1. Sign in to [Google Cloud Console](https://console.cloud.google.com/). Create a project named **MailTask**.
2. Go to **APIs & Services → Library**, open **Gmail API**, and click **Enable**.
3. Go to **IAM & Admin → Service Accounts → Create service account**. Name it **MailTask Gmail Reader**. The Gmail delegation does not require a Google Cloud project role.
4. Open the account. Under **Advanced settings**, note the numeric Client ID under domain-wide delegation. Under **Keys → Add key → Create new key**, select **JSON**. Save the file privately. If key creation is blocked, consult your Cloud organisation administrator about the applicable policy.
5. Sign in to the [Google Admin console](https://admin.google.com/) as a super administrator. Open **Security → Access and data control → API controls → Manage Domain Wide Delegation → Add new**. Enter the numeric Client ID and this one scope, then click **Authorize**.

   ```text
   https://www.googleapis.com/auth/gmail.readonly
   ```

The Workspace grant permits delegation for the domain. The test script requests a token for only the mailbox you give it. Policy changes can take time to propagate.

- [Google Cloud service accounts](https://docs.cloud.google.com/iam/docs/service-accounts-create)
- [Workspace domain-wide delegation](https://knowledge.workspace.google.com/admin/apps/control-api-access-with-domain-wide-delegation)
- [Google Cloud key policy troubleshooting](https://docs.cloud.google.com/iam/docs/troubleshoot-org-policies)

## 2. Prepare Hostinger via hPanel

1. Under **Websites → Dashboard**, find **SSH Access**. Enable it and use the SSH command shown there. A plan with SSH is required for this setup workflow.
2. In hPanel, choose PHP 8.1 or newer. In SSH, run `php -v` and `php -m`. Check that the CLI reports PHP 8.1+ and lists **curl**, **openssl**, and **sodium**. If the default CLI PHP is older, use the versioned PHP binary provided by Hostinger in place of `php` in every command.
3. Use SFTP or File Manager to upload and extract the ZIP into your account home so the app directory is `$HOME/MailTask`. Keep the entire directory outside any existing website's `public_html`. Phase 1 uses SSH only, so you can leave the subdomain unconfigured during this check. For the dashboard deployment, the document root must expose only `MailTask/public`.
4. In SSH, run these commands.

   ```sh
   cd "$HOME/MailTask"
   umask 077
   mkdir -p storage/secrets
   chmod 700 storage/secrets
   cp config/config.sample.php config/config.php
   chmod 600 config/config.php
   php -r 'echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), PHP_EOL;'
   ```

5. Open `config/config.php` in hPanel File Manager or via SFTP. Paste the random value between the quotes for `encryption_key`. Replace `example.com` under `allowed_domain` with your Workspace primary domain.
6. Upload the Google JSON using SFTP to your account home as `mailtask-google.json`. Import it and remove the server's plaintext copy after a successful import.

   ```sh
   cd "$HOME/MailTask"
   chmod 600 "$HOME/mailtask-google.json"
   php cron/import-google.php < "$HOME/mailtask-google.json" && rm "$HOME/mailtask-google.json"
   ```

Keep `config.php` and the encrypted files in private backups. Replacement of the configuration encryption key requires reimporting the Google JSON. If the account key is exposed, revoke it in Google Cloud and import a replacement.

- [Hostinger PHP configuration](https://support.hostinger.com/en/articles/4047803-how-to-change-the-php-version-for-subfolders-or-subdomains)
- [Hostinger subdomain setup](https://support.hostinger.com/en/articles/1583405-how-to-create-and-delete-subdomains-in-hostinger)

## 3. Verify one live mailbox

1. Choose a primary mailbox in the configured Workspace domain. Ensure it has a recent Inbox message. To check both states, send two test messages and mark one as read.
2. In SSH, run the local fixture tests and then the live test. Replace the sample address with the chosen mailbox.

   ```sh
   cd "$HOME/MailTask"
   php tests/gmail.php
   php cron/test-gmail.php test-user@example.com
   ```

3. Verify that both tests report **passed** and that the live script retrieves at least one message. If the Inbox count is zero, send a fresh message and rerun. If the OAuth request fails, check the Client ID, the authorised scope, the server clock, and whether the mailbox is active. If Gmail returns HTTP 403, check that Gmail API is enabled in the service account's project and Workspace delegation has propagated. Share only the pass result or error line from the script when continuing the build. Keep credentials and email contents private.

## Remaining phases

2. Gemini analysis, database installation and cursor-based scan processing.
3. Attachment extraction and analysis.
4. Login, task dashboard, Inbox, and team overview.
5. SMTP digests and reminders.
6. Admin settings, retention, audit, and deployment checks.

Schedule cron and enrol the rest of the company after the later phases are installed and validated with the first mailbox.
