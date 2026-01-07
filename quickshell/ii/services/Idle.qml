pragma Singleton
pragma ComponentBehavior: Bound

import QtQuick
import Quickshell
import Quickshell.Io
import qs.modules.common

Singleton {
    id: root

    property bool inhibit: false
    readonly property int screenOffTimeout: Config.options?.idle?.screenOffTimeout ?? 300
    readonly property int lockTimeout: Config.options?.idle?.lockTimeout ?? 600
    readonly property int suspendTimeout: Config.options?.idle?.suspendTimeout ?? 0

    onScreenOffTimeoutChanged: _restartSwayidle()
    onLockTimeoutChanged: _restartSwayidle()
    onSuspendTimeoutChanged: _restartSwayidle()
    onInhibitChanged: _restartSwayidle()

    function toggleInhibit(active = null) {
        if (active !== null) {
            inhibit = active;
        } else {
            inhibit = !inhibit;
        }
        Persistent.states.idle.inhibit = inhibit;
    }

    function _restartSwayidle() {
        _stopSwayidle()
        if (!inhibit) _startSwayidleDelayed.start()
    }

    function _stopSwayidle() {
        Quickshell.execDetached(["/usr/bin/pkill", "-x", "swayidle"])
    }

    function _startSwayidle() {
        if (inhibit) return

        const cmd = ["/usr/bin/swayidle", "-w"]

        if (screenOffTimeout > 0) {
            cmd.push("timeout", screenOffTimeout.toString(), "/usr/bin/niri msg action power-off-monitors", "resume", "/usr/bin/niri msg action power-on-monitors")
        }

        if (lockTimeout > 0) {
            cmd.push("timeout", lockTimeout.toString(), "/usr/bin/qs -c ii ipc call lock activate")
        }

        if (suspendTimeout > 0) {
            cmd.push("timeout", suspendTimeout.toString(), "/usr/bin/systemctl suspend -i")
        }

        if (Config.options?.idle?.lockBeforeSleep !== false) {
            cmd.push("before-sleep", "/usr/bin/qs -c ii ipc call lock activate")
        }

        console.log("[Idle] Starting swayidle")
        Quickshell.execDetached(cmd)
    }

    Timer {
        id: _startSwayidleDelayed
        interval: 200
        onTriggered: root._startSwayidle()
    }

    Connections {
        target: Config
        function onReadyChanged() {
            if (Config.ready) root._restartSwayidle()
        }
    }

    Connections {
        target: Persistent
        function onReadyChanged() {
            if (Persistent.ready && Persistent.states?.idle?.inhibit)
                root.inhibit = true
        }
    }

    Component.onDestruction: _stopSwayidle()
}
