package com.romavb23.grandmahealth

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** Runs after credential-protected storage becomes available; no Direct Boot access. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        when (intent.action) {
            Intent.ACTION_BOOT_COMPLETED, Intent.ACTION_MY_PACKAGE_REPLACED ->
                MonitoringForegroundService.restore(context)
        }
    }
}
