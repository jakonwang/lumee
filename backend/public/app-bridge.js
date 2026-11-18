/*!
 * Lumée App Bridge — for apps embedded in the Lumée admin.
 *
 *   <script src="https://<platform host>/app-bridge.js"></script>
 *   const bridge = LumeeAppBridge.create()          // reads ?host=… from your URL
 *   const token = await bridge.getSessionToken()    // fresh JWT (HS256, signed with your app's signing secret)
 *   bridge.toast('Saved')                            // or bridge.toast('Failed', { error: true })
 *   bridge.setTitle('Settings')
 *   bridge.redirect('/orders')                      // navigate the admin (admin paths only)
 *
 * Send the token to your backend as "Authorization: Bearer <token>" and verify it there: signature with
 * your signing secret, aud = your client id, exp not passed. Its store_id / store claims say which store.
 */
(function (global) {
  'use strict'

  function create(options) {
    options = options || {}
    var params = new URLSearchParams(global.location.search)
    var host = options.host || params.get('host')
    if (!host || global.parent === global) {
      throw new Error('LumeeAppBridge: not running inside the Lumée admin (missing ?host= or no parent frame).')
    }
    var origin = new URL(host).origin
    var pending = {}
    var initial = params.get('session')
    var initialAt = Date.now()

    global.addEventListener('message', function (ev) {
      if (ev.origin !== origin || !ev.data || ev.data.type !== 'lumee:session') return
      var p = pending[ev.data.id]
      if (!p) return
      delete pending[ev.data.id]
      ev.data.token ? p.resolve(ev.data.token) : p.reject(new Error(ev.data.error || 'session unavailable'))
    })

    function send(msg) {
      global.parent.postMessage(msg, origin)
    }

    return {
      getSessionToken: function () {
        // The token in the URL is good for ~50 seconds; after that, ask the admin for a new one
        if (initial && Date.now() - initialAt < 50000) return Promise.resolve(initial)
        initial = null
        return new Promise(function (resolve, reject) {
          var id = Math.random().toString(36).slice(2)
          pending[id] = { resolve: resolve, reject: reject }
          send({ type: 'lumee:session', id: id })
          setTimeout(function () {
            if (pending[id]) {
              delete pending[id]
              reject(new Error('session timeout'))
            }
          }, 10000)
        })
      },
      toast: function (message, opts) {
        send({ type: 'lumee:toast', message: String(message), error: !!(opts && opts.error) })
      },
      setTitle: function (title) {
        send({ type: 'lumee:title', title: String(title) })
      },
      redirect: function (path) {
        send({ type: 'lumee:redirect', path: String(path) })
      },
    }
  }

  global.LumeeAppBridge = { create: create }
})(window)
