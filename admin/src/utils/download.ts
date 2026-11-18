import { http } from '@/api/http'

/** GET a file from the admin API (Bearer auth, so no plain link) and save it with the server's filename. */
export async function download(path: string, params: Record<string, unknown> = {}, fallback = 'download') {
  const res = await http.get(path, { params, responseType: 'blob' })
  const name = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(res.headers['content-disposition'] ?? '')?.[1]
  const url = URL.createObjectURL(res.data as Blob)
  const a = document.createElement('a')
  a.href = url
  a.download = name ? decodeURIComponent(name) : fallback
  a.click()
  URL.revokeObjectURL(url)
}
