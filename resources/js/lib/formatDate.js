/**
 * ISO / Laravel date → dd-mm-YYYY (tanggal kalender UTC; cocok untuk kolom date-only).
 */
export function formatDdMmYyyy(value) {
    if (value == null || value === '') {
        return '–'
    }
    const d = new Date(value)
    if (Number.isNaN(d.getTime())) {
        return String(value)
    }
    const dd = String(d.getUTCDate()).padStart(2, '0')
    const mm = String(d.getUTCMonth() + 1).padStart(2, '0')
    const yyyy = d.getUTCFullYear()
    return `${dd}-${mm}-${yyyy}`
}
