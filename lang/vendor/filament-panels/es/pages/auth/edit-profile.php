<?php

return [

    'label' => 'Perfil',

    'form' => [

        'email' => [
            'label' => 'Correo electrónico',
        ],

        'name' => [
            'label' => 'Nombre',
        ],

        'password' => [
            'label'                => 'Nueva contraseña',
            'validation_attribute' => 'contraseña',
        ],

        'password_confirmation' => [
            'label' => 'Confirmar nueva contraseña',
        ],

        'actions' => [

            'save' => [
                'label' => 'Guardar cambios',
            ],

        ],

    ],

    'notifications' => [

        'saved' => [
            'title' => 'Guardado',
        ],

    ],

    'actions' => [

        'cancel' => [
            'label' => 'Cancelar',
        ],

    ],

];
