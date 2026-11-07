<?php

declare(strict_types=1);

return [
    'abuse_timezones' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('SYSTEM_ADMIN_ABUSE_TIMEZONES', 'Asia/Tehran,Europe/Moscow,Europe/Kaliningrad,Europe/Samara,Europe/Volgograd,Europe/Saratov,Europe/Ulyanovsk,Europe/Astrakhan,Europe/Kirov,Asia/Yekaterinburg,Asia/Omsk,Asia/Novosibirsk,Asia/Barnaul,Asia/Tomsk,Asia/Novokuznetsk,Asia/Krasnoyarsk,Asia/Irkutsk,Asia/Chita,Asia/Yakutsk,Asia/Khandyga,Asia/Vladivostok,Asia/Ust-Nera,Asia/Magadan,Asia/Sakhalin,Asia/Srednekolymsk,Asia/Kamchatka,Asia/Anadyr')),
    ))),
    'internal_email_domains' => array_values(array_filter(array_map(
        trim(...),
        explode(',', strtolower((string) env('SYSTEM_ADMIN_INTERNAL_EMAIL_DOMAINS', 'relaticle.com,relaticlehq.com'))),
    ))),
];
