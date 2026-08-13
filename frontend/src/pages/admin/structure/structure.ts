/** Map entity rows to `<option>` value/label pairs for selects. */
export function toOptions(rows: Array<{ id: number; name: string }>): Array<{ value: string; label: string }> {
  return rows.map((row) => ({ value: String(row.id), label: row.name }));
}
