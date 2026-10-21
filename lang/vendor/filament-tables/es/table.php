<?php

return [

    'columns' => [

        'messages' => [
            'copied' => 'Copiado',
        ],

        'text' => [
            'actions' => [
                'collapse_list' => 'Mostrar :count menos',
                'expand_list'   => 'Mostrar :count más',
            ],

            'more_list_items' => 'y :count más',
        ],

    ],

    'actions' => [

        'disable_reordering' => [
            'label' => 'Terminar de reordenar',
        ],

        'enable_reordering' => [
            'label' => 'Reordenar registros',
        ],

        'filter' => [
            'label' => 'Filtrar',
        ],

        'group' => [
            'label' => 'Agrupar',
        ],

        'open_bulk_actions' => [
            'label' => 'Acciones masivas',
        ],

        'toggle_columns' => [
            'label' => 'Alternar columnas',
        ],

    ],

    'empty' => [

        'heading' => 'No se encontraron registros',

        'description' => '',

    ],

    'filters' => [

        'actions' => [

            'apply' => [
                'label' => 'Aplicar filtros',
            ],

            'remove' => [
                'label' => 'Quitar filtro',
            ],

            'remove_all' => [
                'label' => 'Quitar todos los filtros',
            ],

            'reset' => [
                'label' => 'Reiniciar',
            ],

        ],

        'heading' => 'Filtros',

        'indicator' => 'Filtros activos',

        'multi_select' => [
            'placeholder' => 'Todos',
        ],

        'select' => [
            'placeholder' => 'Todos',
        ],

        'trashed' => [

            'label' => 'Registros eliminados',

            'only_trashed' => 'Solo eliminados',

            'with_trashed' => 'Con eliminados',

            'without_trashed' => 'Sin eliminados',

        ],

    ],

    'grouping' => [

        'fields' => [

            'group' => [
                'label'       => 'Agrupar por',
                'placeholder' => 'Agrupar por',
            ],

            'direction' => [

                'label' => 'Dirección de agrupación',

                'options' => [
                    'asc'  => 'Ascendente',
                    'desc' => 'Descendente',
                ],

            ],

        ],

    ],

    'reorder_indicator' => 'Arrastra y suelta los registros en el orden deseado.',

    'selection_indicator' => [

        'selected_count' => '1 registro seleccionado|:count registros seleccionados',

        'actions' => [

            'deselect_all' => [
                'label' => 'Deseleccionar todos',
            ],

            'select_all' => [
                'label' => 'Seleccionar todos',
            ],

        ],

    ],

    'sorting' => [

        'fields' => [

            'column' => [
                'label' => 'Ordenar por',
            ],

            'direction' => [

                'label' => 'Dirección de orden',

                'options' => [
                    'asc'  => 'Ascendente',
                    'desc' => 'Descendente',
                ],

            ],

        ],

    ],

    'summary' => [

        'heading' => 'Resumen',

        'subheadings' => [
            'all'   => 'Todos los :label',
            'group' => 'Resumen de :group',
            'page'  => 'Esta página',
        ],

        'summarizers' => [

            'average' => [
                'label' => 'Promedio',
            ],

            'count' => [
                'label' => 'Cantidad',
            ],

            'sum' => [
                'label' => 'Suma',
            ],

        ],

    ],

];
