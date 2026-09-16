<?php
require_once 'tg-helpers.php';

// Лимит времени — 25 секунд (cron-job.org ждёт максимум 30)
set_time_limit(25);

// Храним offset в файле
define('OFFSET_FILE', DATA_DIR . 'offset.txt');

$offset = 0;
if (file_exists(OFFSET_FILE)) {
    $offset = (int)file_get_contents(OFFSET_FILE);
}

// Запрашиваем обновления (timeout=0 — только новые, без ожидания)
$url = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/getUpdates?offset=' . $offset . '&timeout=0';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
$resp = curl_exec($ch);
curl_close($ch);

$data = json_decode($resp, true);

if (!$data || !($data['ok'] ?? false)) {
    exit('API error');
}

$updates = $data['result'] ?? [];

foreach ($updates as $update) {
    $offset = $update['update_id'] + 1;
    file_put_contents(OFFSET_FILE, $offset);

    if (!isset($update['message'])) continue;

    $msg = $update['message'];
    $chatId = $msg['chat']['id'];
    $text = trim($msg['text'] ?? '');

    // /start
    if ($text === '/start') {
        tgSend($chatId,
            "Привет! Я бот проекта VEXXIS.\n\n" .
            "Как подключить уведомления:\n" .
            "1. Отправь идею на сайте VEXXIS.\n" .
            "2. На экране появится 6-значный код.\n" .
            "3. Отправь этот код сюда.\n\n" .
            "Отключить уведомления: /stop."
        );
        continue;
    }

    // /stop
    if ($text === '/stop') {
        $links = loadLinks();
        $found = false;
        foreach ($links as $email => $cid) {
            if ($cid == $chatId) {
                unset($links[$email]);
                $found = true;
            }
        }
        saveLinks($links);
        tgSend($chatId, $found
            ? "Уведомления отключены.\n\nЕсли захочешь снова — отправь новый код с сайта."
            : "Ты ещё не подключён. Отправь код с сайта, чтобы получать уведомления."
        );
        continue;
    }

    // Проверка кода
    if (preg_match('/^\d{6}$/', $text)) {
        $email = getEmailByCode($text);
        if ($email) {
            linkEmailToChat($email, $chatId);
            tgSend($chatId, "Подтверждено!\n\nТеперь на $email будут приходить уведомления.\n\nОтключить: /stop");
        } else {
            tgSend($chatId, "Код не найден или устарел.\n\nОтправь идею на сайте заново.");
        }
        continue;
    }

    tgSend($chatId, "Отправь /start чтобы подключить уведомления, или /stop чтобы отключить.");
}

// Создаём offset.txt если его не было
if (!file_exists(OFFSET_FILE)) {
    file_put_contents(OFFSET_FILE, $offset);
}