<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(503);
echo "MailTask dashboard is pending. Complete the Phase 1 Gmail check by SSH.\n";
