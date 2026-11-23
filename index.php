<?php
/**
 * Site root. The document root is the project root and the start page lives
 * at /public/index.php (it sends visitors to sign-in or their dashboard), so
 * the bare domain redirects there instead of answering 403.
 */
header('Location: /public/index.php', true, 302);
exit;
