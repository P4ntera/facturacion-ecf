<?php

return [

    'title' => 'Restablecer contraseña',

    'heading' => '¿Olvidaste tu contraseña?',

    'actions' => [

        'login' => [
            'label' => 'Volver a iniciar sesión',
        ],

    ],

    'form' => [

        'email' => [
            'label' => 'Correo electrónico',
        ],

        'actions' => [

            'request' => [
                'label' => 'Enviar correo',
            ],

        ],

    ],

    'notifications' => [

        'throttled' => [
            'title' => 'Demasiadas solicitudes',
            'body'  => 'Por favor, intenta de nuevo en :seconds segundos.',
        ],

    ],

];
