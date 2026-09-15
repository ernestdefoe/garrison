/**
 * 🚨 BINARY UNITS, because that is what every one of these sources reports.
 *
 * Docker says GiB, cgroup v2 counts pages, /proc counts pages, and a backup is
 * a file on disk. Dividing by 1000 would put a number on screen that disagrees
 * with `docker stats` and `ls -lh` on the same machine — and an operator who
 * catches the panel contradicting their own terminal believes the panel
 * exactly once.
 *
 * One copy, shared. It was two for a while — the status page and the backups
 * panel each had their own — which is the shape where a rounding fix lands in
 * one of them and the two surfaces start quietly disagreeing about the size of
 * the same thing.
 */
export function bytes(n) {
  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
  let i = 0;

  while (n >= 1024 && i < units.length - 1) {
    n /= 1024;
    i++;
  }

  return (i === 0 ? n : n.toFixed(n >= 10 ? 0 : 1)) + ' ' + units[i];
}
