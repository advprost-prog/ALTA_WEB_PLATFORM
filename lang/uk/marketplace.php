<?php

return [
    'page_title' => 'Marketplace додатків',
    'operations' => [
        'heading' => 'Операції та відновлення', 'status' => 'Стан', 'unresolved' => 'незавершені', 'manual' => 'ручне втручання',
        'corrupt' => 'пошкоджені backups', 'pending' => 'очікують очищення', 'refresh' => 'Оновити діагностику',
        'operation' => 'Операція', 'addon' => 'Додаток', 'state' => 'Стан', 'classification' => 'Класифікація',
        'evidence' => 'Докази', 'actions' => 'Дії', 'dry_run' => 'Перевірити план', 'run_safe' => 'Безпечно відновити',
        'rollback_preflight' => 'Перевірити rollback', 'mark_manual' => 'Позначити ручною', 'manual_reason' => 'Причина ручного втручання',
        'completed_rollback' => 'Rollback завершеного оновлення', 'rollback_dry' => 'Перевірити rollback', 'execute_rollback' => 'Виконати rollback',
    ],
    'backups' => ['heading' => 'Зберігання backups', 'none' => 'Керованих backups немає.', 'last_good' => 'остання справна', 'reference' => 'посилання незавершеної операції', 'cleanup' => 'Очистити точний backup'],
    'stale' => ['heading' => 'Застарілі recovery-дані', 'none' => 'Застарілих керованих залишків немає.', 'cleanup' => 'Очистити точний елемент'],
    'release_gate' => 'Production Registry коректний, але порожній. Для production end-to-end перевірки потрібен авторизований підписаний release.',
];
