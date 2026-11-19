---
title: Administre su perfil
description: Administre su perfil, contraseña, llaves de acceso, autenticación de dos factores y sesiones del navegador. Cómo solicitar la eliminación de su cuenta.
order: 4
updated: "2026-09-09"
related: [help/workspace/manage-members-and-roles, help/tasks-and-notes/work-with-tasks]
---

Su configuración personal está bajo su avatar, en la esquina superior derecha.
Elija **Configuración**. Es solo suya y lo acompaña en cada espacio de trabajo
al que pertenece.

## Información del perfil

Suba una foto de perfil (recórtela en el editor, o use **Quitar foto** para
volver a las iniciales) y cambie su nombre o su correo. Al cambiar el correo se
envía un enlace de verificación a la nueva dirección. El cambio se aplica cuando
lo confirma.

**Zona horaria** decide cómo se leen para usted todas las fechas y horas de la
aplicación. Los demás miembros conservan su propia configuración, así que cada
uno ve el mismo registro en su hora local.

## Contraseña

Abra la pestaña **Seguridad** para su contraseña, llaves de acceso, autenticación de dos factores y sesiones del navegador.

Ingrese y confirme su nueva contraseña, y luego elija **Guardar**.
Confirme su identidad con una llave de acceso, una contraseña o un proveedor vinculado.
Las cuentas sin contraseña muestran **Establecer contraseña** en la misma sección.

## Llaves de acceso

Una llave de acceso le permite iniciar sesión con Face ID, Touch ID o el bloqueo de
pantalla de su dispositivo, en lugar de una contraseña. **Llaves de acceso**
enumera las que tiene en su cuenta, cuándo se agregó cada una y cuándo se usó por
última vez.

**Agregar llave de acceso** confirma que es usted y luego pasa el control a su
navegador o administrador de contraseñas para crear la llave. Relaticle le pone
el nombre del autenticador que reconoce. Use **Renombrar** si prefiere
etiquetarla por dispositivo.
**Quitar** elimina una llave de acceso de su cuenta. Ese dispositivo ya no puede iniciar su sesión.
Relaticle conserva su último método para iniciar sesión. Agregue otra llave de acceso o defina una contraseña antes de quitarlo.

La próxima vez que inicie sesión, escriba su correo y aparecerá solo el aviso de
la llave de acceso. En un dispositivo que ya tenga una, el campo de correo la
ofrece al hacer clic en él.

## Confirme que es usted

Los cambios sensibles piden confirmar su identidad con una llave de acceso o una contraseña.
Una llave de acceso confirma su identidad con su huella, su rostro o el PIN del dispositivo.
Si usa una contraseña y tiene activada la autenticación de dos factores, ingrese también un código del autenticador.

Si solo inicia sesión con Google o Microsoft, confirme a través de ese proveedor.
También ingresa un código del autenticador si tiene activada la autenticación de dos factores.

## Autenticación de dos factores

En **Seguridad**, elija **Activar** en **Autenticación de dos factores**.
Confirme su identidad, escanee el código con su aplicación de autenticación e ingrese su código de seis dígitos.
Guarde sus códigos de recuperación antes de cerrar la ventana de configuración.

Iniciar sesión con una llave de acceso satisface la autenticación de dos factores sin un código adicional del autenticador.
Iniciar sesión con una contraseña, Google o Microsoft sigue requiriendo un código del autenticador.
Use un código de recuperación si no puede acceder a su aplicación de autenticación.

## Sesiones del navegador

**Sesiones del navegador** enumera todos los lugares donde ha iniciado sesión:
dispositivo, navegador y última actividad. **Cerrar las demás sesiones del navegador** cierra la sesión en todos los dispositivos excepto el que está usando. Hágalo siempre que haya usado un dispositivo compartido.

Cambiar o restablecer su contraseña también cierra la sesión en sus otros
dispositivos.

En una instalación autoalojada que guarda las sesiones en Redis, **Cerrar las demás sesiones del navegador** funciona de otra manera. Cierra la sesión de un
dispositivo solo después de que este permanece inactivo durante dos horas, la
duración predeterminada de la sesión. Un dispositivo con Relaticle abierto
mantiene la sesión iniciada. Cambie su contraseña para cerrarla de inmediato.

## Notificaciones

La página **Notificaciones**, junto a la configuración de su perfil, controla las
alertas de asignación de tareas y el resumen diario, descritos en
[Trabaje con tareas](/es/help/tasks-and-notes/work-with-tasks).

## Elimine su cuenta

Para solicitar la eliminación de su cuenta, escriba a [privacy@relaticle.com](mailto:privacy@relaticle.com) o [contáctenos](/es/contact).
En una instalación autoalojada, comuníquese con la persona que la administra.

Si **Eliminar cuenta** está disponible al final de **Perfil**, puede programar la eliminación allí.
Escriba el correo de su cuenta y confirme su identidad. La eliminación se programa para dentro de **30 días**.
Inicie sesión durante esos 30 días y elija **Conservar mi cuenta** para cancelarla.
Pasado el período de gracia, su cuenta y sus datos personales se eliminan de forma permanente.
Los registros de los espacios de trabajo compartidos permanecen.

Antes de programar la eliminación, quite a los demás miembros de los espacios de trabajo que usted posee, o elimine esos espacios de trabajo.
