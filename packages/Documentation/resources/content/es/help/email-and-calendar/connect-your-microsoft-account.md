---
title: Conecte su cuenta de Microsoft
description: Vincule un buzón de Outlook o Microsoft 365 para sincronizar su correo y calendario con Relaticle, y vea qué acceso le pide Relaticle a Microsoft.
order: 2
updated: "2026-11-08"
related: [help/email-and-calendar/connect-your-google-account, help/email-and-calendar/read-and-reply-to-email, help/email-and-calendar/choose-who-sees-your-email, help/email-and-calendar/see-meetings-and-link-them-to-records]
---

Al conectar una cuenta de Microsoft, un buzón de Outlook o Microsoft 365 y su
calendario llegan a Relaticle. Los correos y las reuniones aparecen en los
registros que involucran, y usted puede enviar desde Relaticle con esa dirección.

## Conéctela

1. Abra el menú del espacio de trabajo en la parte superior de la barra lateral
   y elija **Configuración del espacio de trabajo**.
2. Abra la pestaña **Correo electrónico y calendario**. Se abre en **Buzones**.
3. Haga clic en **Conectar cuenta de Microsoft**.
4. Inicie sesión en Microsoft y acepte el acceso que se indica.
5. Microsoft lo devuelve a Relaticle, y la cuenta aparece en
   **Buzones conectados**.

La página **Correos**, la pestaña **Correos electrónicos** de un registro y el
panel **Reuniones** en **Inicio** muestran el mismo botón hasta que usted
conecte una cuenta.

![La pestaña Buzones en Correo electrónico y calendario, con el botón Conectar cuenta de Microsoft junto a Conectar cuenta de Google debajo de los buzones conectados](/help-assets/email-and-calendar/connect-your-google-account-1.png)

En una instalación autoalojada, el botón aparece cuando se configura el cliente
de Microsoft. La [Guía de autoalojamiento](/es/developers/self-hosting) enumera
los ajustes.

## Qué le pide Relaticle a Microsoft

- **Leer su correo**, para que los mensajes puedan aparecer en sus registros.
- **Enviar correo como usted**, para que lo que escriba en Relaticle salga desde
  su dirección.
- **Leer y actualizar su calendario**, para que las reuniones aparezcan y su
  respuesta llegue a su calendario.
- **Leer su perfil**, para que Relaticle sepa qué dirección conectó.
- **Mantener este acceso**, para que la sincronización continúe mientras usted
  no tiene una sesión iniciada en Microsoft.

Relaticle nunca modifica, mueve ni elimina mensajes de su buzón, y nunca
importa sus borradores. En su calendario, solo cambia su propia respuesta a una
invitación.

## Qué carpetas se sincronizan

Relaticle sincroniza todas las carpetas de correo, incluidas las carpetas dentro
de su bandeja de entrada y las carpetas a las que una regla mueve correo. Excluye
**Borradores**, **Correo no deseado**, **Elementos eliminados**,
**Bandeja de salida**, **Clutter** e **Historial de conversaciones**, junto con
cualquier carpeta dentro de ellas.

## Qué sucede después de conectar

La cuenta pertenece al espacio de trabajo en el que usted la conectó. Relaticle
importa su buzón y su calendario en segundo plano, y la cuenta muestra
**Sincronizando** con un porcentaje hasta que indica **Sincronizado**.

Lo que su espacio de trabajo ve de cada correo depende del nivel de uso
compartido del buzón.
[Elija quién ve su correo](/es/help/email-and-calendar/choose-who-sees-your-email)
antes de conectar un buzón que usted considere sensible.

Una cuenta de Microsoft tiene el mismo menú que una cuenta de Google. Consulte
[Administre una cuenta conectada](/es/help/email-and-calendar/connect-your-google-account#administre-una-cuenta-conectada)
para ver su configuración, **Establecer como predeterminado** y
**Desconectar buzón**.

## Si Microsoft pide aprobación del administrador

Algunas organizaciones permiten que solo un administrador apruebe una aplicación
nueva. Pida al administrador de Microsoft 365 que apruebe Relaticle y luego
conecte de nuevo.

## Si la cuenta muestra Debe reconectar

Microsoft dejó de aceptar el acceso guardado. Abra el menú de la cuenta y haga
clic en **Reconectar**, luego inicie sesión y acepte el acceso de nuevo.

## Después de desconectar

**Desconectar buzón** detiene la sincronización y elimina los tokens de acceso
que Relaticle conserva. Microsoft sigue listando a Relaticle entre las
aplicaciones que usted aprobó. Quítelo en la configuración de su cuenta de
Microsoft para terminar esa aprobación.
