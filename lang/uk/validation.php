<?php

return [
    'required' => 'Поле «:attribute» обов’язкове.',
    'required_if' => 'Поле «:attribute» обов’язкове, коли :other має значення :value.',
    'exists' => 'Обране значення поля «:attribute» недійсне.',
    'unique' => 'Таке значення поля «:attribute» вже використовується.',
    'in' => 'Обране значення поля «:attribute» недійсне.',
    'not_in' => 'Обране значення поля «:attribute» недопустиме.',
    'integer' => 'Поле «:attribute» має бути цілим числом.',
    'numeric' => 'Поле «:attribute» має бути числом.',
    'email' => 'Поле «:attribute» має містити дійсну адресу електронної пошти.',
    'string' => 'Поле «:attribute» має бути рядком.',
    'date' => 'Поле «:attribute» має містити дійсну дату.',
    'confirmed' => 'Підтвердження поля «:attribute» не збігається.',
    'current_password' => 'Неправильний пароль.',
    'max' => [
        'string' => 'Поле «:attribute» має містити не більше :max символів.',
        'numeric' => 'Поле «:attribute» не може бути більшим за :max.',
    ],
    'min' => [
        'string' => 'Поле «:attribute» має містити щонайменше :min символів.',
        'numeric' => 'Поле «:attribute» не може бути меншим за :min.',
    ],
    'attributes' => [
        'user_id' => 'Акаунт клієнта',
        'coach_user_id' => 'Відповідальний тренер',
        'status' => 'Статус супроводу',
        'coaching_started_at' => 'Початок супроводу',
        'email' => 'Електронна пошта',
        'password' => 'Пароль',
    ],
];
