<?php

return [

    'title' => 'Iniciar sesión',

    'heading' => 'Iniciar sesión',

    'actions' => [

        'register' => [
            'before' => '¿No tienes cuenta?',
            'label'  => 'Regístrate',
        ],

        'request_password_reset' => [
            'label' => '¿Olvidaste tu contraseña?',
        ],

    ],

    'form' => [

        'email' => [
            'label' => 'Correo electrónico',
        ],

        'password' => [
            'label' => 'Contraseña',
        ],

        'remember' => [
            'label' => 'Recordarme',
        ],

        'actions' => [

            'authenticate' => [
                'label' => 'Iniciar sesión',
            ],

        ],

    ],

    'messages' => [

        'failed' => 'Estas credenciales no coinciden con nuestros registros.',

        'throttled' => 'Demasiados intentos de inicio de sesión. Por favor, intenta de nuevo en :seconds segundos.',

    ],

    'notifications' => [

        'throttled' => [
            'title' => 'Demasiados intentos',
            'body'  => 'Por favor, intenta de nuevo en :seconds segundos.',
        ],

    ],

];
