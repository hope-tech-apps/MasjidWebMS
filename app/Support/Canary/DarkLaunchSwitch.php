<?php

namespace App\Support\Canary;

/**
 * The ONLY code `canary.dark_launches` may name, and the only method TenancyCanary will call on it.
 *
 * Why an interface and not a `[Class::class, 'method']` pair: config naming a method for this command to execute
 * is the shape of the deleteAllMedia incident (TenancyCanary::attributableRelationsNotConfigured). A class has to be
 * WRITTEN to implement this, and nothing but darkBecause() is ever invoked on it. A class-string survives config:cache.
 *
 * Contract for implementations:
 *  - READ-ONLY. At most one plain SELECT. No Cache::remember, and no Cache::get either (on the database store a get
 *    deletes expired rows). No log line (the run leaves exactly one), no event, no write of any kind.
 *  - May throw. The canary reads a throw as LIVE and probes the endpoint, so a broken switch is never a silent skip:
 *    the run names the switch `unreadable`, and if the endpoint really is dark the run that probes it names it
 *    NOT REACHED (exit 3). An endpoint whose own reader fails open — AppMenu::killed() does — is simply live then,
 *    and probed as live.
 *  - null or '' means live. While the endpoint is dark ON PURPOSE, return one sentence of evidence: what is set, when,
 *    by whom, and why. It is printed in the report and in the scheduled log line, so it must hold no secret.
 *  - `final`, no traits.
 */
interface DarkLaunchSwitch
{
    public static function darkBecause(): ?string;
}
