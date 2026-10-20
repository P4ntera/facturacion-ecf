; =============================================================================
; POS - Atajos de teclado (AutoHotkey v2)
;
; Remapea F1–F9 a Ctrl+Alt+Shift+1–9 SOLO cuando el navegador (Edge o Chrome)
; está activo. El POS escucha ambas variantes, así que funciona con o sin este
; script (en kiosk/Linux las F llegan directo).
;
; Instalación:
;   1. Instalar AutoHotkey v2  →  https://www.autohotkey.com/
;   2. Doble-clic en este archivo .ahk (o ponerlo en el Startup de Windows)
;
; Para que arranque con Windows:
;   Win+R → shell:startup → copiar un acceso directo de este .ahk ahí
; =============================================================================

#Requires AutoHotkey v2.0
#SingleInstance Force

; Solo activo en el navegador
#HotIf WinActive("ahk_exe msedge.exe") or WinActive("ahk_exe chrome.exe")

F1::{
    Send "^!+1"
}

F2::{
    Send "^!+2"
}

F3::{
    Send "^!+3"
}

F4::{
    Send "^!+4"
}

F5::{
    Send "^!+5"
}

F6::{
    Send "^!+6"
}

F7::{
    Send "^!+7"
}

F8::{
    Send "^!+8"
}

F9::{
    Send "^!+9"
}

#HotIf
