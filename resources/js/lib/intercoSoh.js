// The Sender SOH an interco line, or the Add Items form, can still use.
//
// Nothing leaves the Sender Store until the transfer is received, so every line already in the
// transfer holds its quantity. Units of one item can share one balance (1 Bag = 1000 Gm):
// `stock_row_id` names that balance and `stock_factor` converts the line's unit into it
// (quantity x factor = quantity in the balance's unit), as the server's save check does.

const balanceKey = (line) => line.stock_row_id ?? `${line.item_code}|${line.unit_of_measurement}`
const factorOf = (line) => Number(line.stock_factor) || 1

// What the transfer's lines, other than `except`, hold of `target`'s balance, in the balance's unit
export function heldInTransfer(lines, target, except = null) {
    const key = balanceKey(target)
    return lines.reduce(
        (total, line) => (line === except || balanceKey(line) !== key
            ? total
            : total + Number(line.quantity_ordered || 0) * factorOf(line)),
        0,
    )
}

// The Sender SOH left for `target`, in its own unit, once the other lines have taken theirs
export function sohLeft(lines, target, except = null) {
    const left = Number(target.stock || 0) - heldInTransfer(lines, target, except) / factorOf(target)
    return Math.max(0, Math.round(left * 10000) / 10000)
}

// 1.5 -> "1.50", 0.125 -> "0.125": never rounds a quantity up past what is left
export const formatSoh = (value) =>
    Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 })

// A quantity is within what is left (a float rounding error is not "more")
export const exceedsSoh = (quantity, left) => Number(quantity) > left + 0.00001
