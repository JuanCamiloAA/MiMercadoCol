<?php

use Illuminate\Support\Facades\Schedule;

// La cartera se revisa a primera hora: quien amanezca vencido no puede volver a comprar a credito.
Schedule::command('credit:block-overdue')->dailyAt('06:00');

// Membresias: cobra renovaciones, mueve a gracia o suspende lo vencido y avisa. De madrugada,
// antes de que abran las cajas; sin solaparse, porque dos rondas a la vez cobrarian dos veces.
Schedule::command('membership:process')->dailyAt('03:00')->withoutOverlapping();

// Saldo a favor: lo que vencio ayer deja de estar disponible (el cobro ya no lo toma; esto lo cierra
// en el libro para que el saldo guardado cuadre con lo que el cliente puede gastar).
Schedule::command('store-credit:expire')->dailyAt('02:30')->withoutOverlapping();
