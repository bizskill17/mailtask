<?php
declare(strict_types=1);

/** Read-only Gmail API wrapper. History stays unfiltered so label removals remain observable. */
final class GmailClient
{
    public function __construct(private GoogleAuth $auth, private Http $http, private string $mailbox)
    {
    }

    public function profile(): array
    {
        return $this->get('profile');
    }

    public function inbox(int $after, int $limit = 20, ?string $page = null): array
    {
        return $this->get('messages', [
            'labelIds' => 'INBOX',
            'q' => 'after:' . $after,
            'maxResults' => max(1, min(500, $limit)),
            'pageToken' => $page,
        ]);
    }

    public function history(string $start, ?string $page = null): array
    {
        return $this->get('history', ['startHistoryId' => $start, 'maxResults' => 20, 'pageToken' => $page]);
    }

    public function message(string $id, bool $metadataOnly = true): array
    {
        return $this->get('messages/' . rawurlencode($id), ['format' => $metadataOnly ? 'metadata' : 'full']);
    }

    public function attachment(string $id, string $attachmentId): array
    {
        return $this->get('messages/' . rawurlencode($id) . '/attachments/' . rawurlencode($attachmentId));
    }

    private function get(string $path, array $query = []): array
    {
        $parameters = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/' . $path . ($parameters === '' ? '' : '?' . $parameters);
        try {
            return $this->http->json('GET', $url, ['Authorization: Bearer ' . $this->auth->token($this->mailbox)]);
        } catch (RuntimeException $error) {
            if ($error->getCode() !== 401) {
                throw $error;
            }
            return $this->http->json('GET', $url, ['Authorization: Bearer ' . $this->auth->token($this->mailbox, true)]);
        }
    }
}
