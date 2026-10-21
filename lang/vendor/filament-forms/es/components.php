<?php

return [

    'builder' => [

        'actions' => [

            'clone' => [
                'label' => 'Clonar',
            ],

            'add' => [
                'label'          => 'Agregar a :label',
                'modal'          => [
                    'heading' => 'Agregar a :label',

                    'actions' => [

                        'add' => [
                            'label' => 'Agregar',
                        ],

                    ],
                ],
            ],

            'add_between' => [
                'label'          => 'Insertar entre bloques',
                'modal'          => [
                    'heading' => 'Agregar a :label',

                    'actions' => [

                        'add' => [
                            'label' => 'Agregar',
                        ],

                    ],
                ],
            ],

            'delete' => [
                'label' => 'Eliminar',
            ],

            'edit' => [
                'label' => 'Editar',

                'modal' => [
                    'heading' => 'Editar bloque',

                    'actions' => [

                        'save' => [
                            'label' => 'Guardar cambios',
                        ],

                    ],
                ],
            ],

            'reorder' => [
                'label' => 'Mover',
            ],

            'move_down' => [
                'label' => 'Mover abajo',
            ],

            'move_up' => [
                'label' => 'Mover arriba',
            ],

            'collapse' => [
                'label' => 'Colapsar',
            ],

            'expand' => [
                'label' => 'Expandir',
            ],

            'collapse_all' => [
                'label' => 'Colapsar todo',
            ],

            'expand_all' => [
                'label' => 'Expandir todo',
            ],

        ],

    ],

    'checkbox_list' => [

        'actions' => [

            'deselect_all' => [
                'label' => 'Deseleccionar todo',
            ],

            'select_all' => [
                'label' => 'Seleccionar todo',
            ],

        ],

    ],

    'file_upload' => [

        'editor' => [

            'actions' => [

                'cancel' => [
                    'label' => 'Cancelar',
                ],

                'drag_crop' => [
                    'label' => 'Modo arrastrar "recortar"',
                ],

                'drag_move' => [
                    'label' => 'Modo arrastrar "mover"',
                ],

                'flip_horizontal' => [
                    'label' => 'Voltear imagen horizontalmente',
                ],

                'flip_vertical' => [
                    'label' => 'Voltear imagen verticalmente',
                ],

                'move_down' => [
                    'label' => 'Mover imagen abajo',
                ],

                'move_left' => [
                    'label' => 'Mover imagen a la izquierda',
                ],

                'move_right' => [
                    'label' => 'Mover imagen a la derecha',
                ],

                'move_up' => [
                    'label' => 'Mover imagen arriba',
                ],

                'resize' => [
                    'label' => 'Redimensionar',
                ],

                'rotate_left' => [
                    'label' => 'Rotar imagen a la izquierda',
                ],

                'rotate_right' => [
                    'label' => 'Rotar imagen a la derecha',
                ],

                'save' => [
                    'label' => 'Guardar',
                ],

                'set_aspect_ratio' => [
                    'label' => 'Establecer relación de aspecto a :ratio',
                ],

                'zoom_100' => [
                    'label' => 'Zoom a 100%',
                ],

                'zoom_in' => [
                    'label' => 'Acercar',
                ],

                'zoom_out' => [
                    'label' => 'Alejar',
                ],

            ],

            'fields' => [

                'height' => [
                    'label' => 'Alto',
                    'unit'  => 'px',
                ],

                'rotation' => [
                    'label' => 'Rotación',
                    'unit'  => 'grados',
                ],

                'width' => [
                    'label' => 'Ancho',
                    'unit'  => 'px',
                ],

                'x_position' => [
                    'label' => 'X',
                    'unit'  => 'px',
                ],

                'y_position' => [
                    'label' => 'Y',
                    'unit'  => 'px',
                ],

            ],

            'aspect_ratios' => [

                'label' => 'Relaciones de aspecto',

                'no_fixed' => [
                    'label' => 'Libre',
                ],

            ],

        ],

    ],

    'key_value' => [

        'actions' => [

            'add' => [
                'label' => 'Agregar fila',
            ],

            'delete' => [
                'label' => 'Eliminar fila',
            ],

            'reorder' => [
                'label' => 'Reordenar fila',
            ],

        ],

        'fields' => [

            'key' => [
                'label' => 'Clave',
            ],

            'value' => [
                'label' => 'Valor',
            ],

        ],

    ],

    'markdown_editor' => [

        'toolbar_buttons' => [

            'attach_files' => 'Adjuntar archivos',
            'blockquote'   => 'Cita',
            'bold'         => 'Negrita',
            'bullet_list'  => 'Lista con viñetas',
            'code_block'   => 'Bloque de código',
            'heading'      => 'Encabezado',
            'italic'       => 'Cursiva',
            'link'         => 'Enlace',
            'ordered_list' => 'Lista numerada',
            'redo'         => 'Rehacer',
            'strike'       => 'Tachado',
            'table'        => 'Tabla',
            'undo'         => 'Deshacer',

        ],

    ],

    'repeater' => [

        'actions' => [

            'add' => [
                'label' => 'Agregar a :label',
            ],

            'add_between' => [
                'label' => 'Insertar entre',
            ],

            'clone' => [
                'label' => 'Clonar',
            ],

            'collapse' => [
                'label' => 'Colapsar',
            ],

            'collapse_all' => [
                'label' => 'Colapsar todo',
            ],

            'delete' => [
                'label' => 'Eliminar',
            ],

            'expand' => [
                'label' => 'Expandir',
            ],

            'expand_all' => [
                'label' => 'Expandir todo',
            ],

            'move_down' => [
                'label' => 'Mover abajo',
            ],

            'move_up' => [
                'label' => 'Mover arriba',
            ],

            'reorder' => [
                'label' => 'Mover',
            ],

        ],

    ],

    'rich_editor' => [

        'toolbar_buttons' => [

            'attach_files' => 'Adjuntar archivos',
            'blockquote'   => 'Cita',
            'bold'         => 'Negrita',
            'bullet_list'  => 'Lista con viñetas',
            'code_block'   => 'Bloque de código',
            'h1'           => 'Título',
            'h2'           => 'Encabezado',
            'h3'           => 'Subencabezado',
            'italic'       => 'Cursiva',
            'link'         => 'Enlace',
            'ordered_list' => 'Lista numerada',
            'redo'         => 'Rehacer',
            'strike'       => 'Tachado',
            'underline'    => 'Subrayado',
            'undo'         => 'Deshacer',

        ],

    ],

    'select' => [

        'actions' => [

            'create_option' => [
                'label' => 'Crear',

                'modal' => [
                    'heading' => 'Crear',

                    'actions' => [

                        'create' => [
                            'label' => 'Crear',
                        ],

                        'create_another' => [
                            'label' => 'Crear y crear otro',
                        ],

                    ],
                ],
            ],

            'edit_option' => [
                'label' => 'Editar',

                'modal' => [
                    'heading' => 'Editar',

                    'actions' => [

                        'save' => [
                            'label' => 'Guardar',
                        ],

                    ],
                ],
            ],

        ],

        'boolean' => [
            'true'  => 'Sí',
            'false' => 'No',
        ],

        'loading_message'    => 'Cargando...',
        'max_items_message'  => 'Solo se pueden seleccionar :count.',
        'no_search_results_message' => 'No se encontraron resultados.',
        'placeholder'        => 'Selecciona una opción',
        'searching_message'  => 'Buscando...',
        'search_prompt'      => 'Empieza a escribir para buscar...',

    ],

    'tags_input' => [
        'placeholder' => 'Nueva etiqueta',
    ],

    'text_input' => [

        'actions' => [

            'hide_password' => [
                'label' => 'Ocultar contraseña',
            ],

            'show_password' => [
                'label' => 'Mostrar contraseña',
            ],

        ],

    ],

    'toggle_buttons' => [
        'boolean' => [
            'true'  => 'Sí',
            'false' => 'No',
        ],
    ],

    'wizard' => [

        'actions' => [

            'previous_step' => [
                'label' => 'Anterior',
            ],

            'next_step' => [
                'label' => 'Siguiente',
            ],

        ],

    ],

];
