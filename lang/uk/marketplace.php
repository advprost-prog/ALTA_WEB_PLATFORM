<?php

return [
    'page_title' => 'Marketplace додатків',
    'operations' => [
        'heading' => 'Операції та відновлення', 'status' => 'Стан', 'unresolved' => 'незавершених операцій', 'manual' => 'ручне втручання',
        'corrupt' => 'пошкоджених резервних копій', 'pending' => 'очікують очищення', 'refresh' => 'Оновити діагностику',
        'operation' => 'Операція', 'addon' => 'Додаток', 'state' => 'Стан', 'classification' => 'Класифікація',
        'evidence' => 'Докази', 'actions' => 'Дії', 'dry_run' => 'Перевірити план', 'run_safe' => 'Безпечно відновити',
        'rollback_preflight' => 'Перевірити відкат', 'mark_manual' => 'Позначити ручною', 'manual_reason' => 'Причина ручного втручання',
        'completed_rollback' => 'Відкат завершеного оновлення', 'rollback_dry' => 'Перевірити відкат', 'execute_rollback' => 'Виконати відкат',
    ],
    'backups' => ['heading' => 'Резервні копії', 'none' => 'Керованих резервних копій немає.', 'last_good' => 'остання справна', 'reference' => 'посилання незавершеної операції', 'cleanup' => 'Видалити резервну копію'],
    'stale' => ['heading' => 'Застарілі службові дані', 'none' => 'Застарілих керованих службових даних немає.', 'cleanup' => 'Видалити службові дані'],
    'release_gate' => 'Production Registry коректний, але порожній. Для production end-to-end перевірки потрібен авторизований підписаний release.',
];
