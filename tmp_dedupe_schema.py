from pathlib import Path
p = Path('sportssync_postgres_schema.sql')
lines = p.read_text(encoding='utf-8').splitlines()
seen = set()
out = []
for line in lines:
    if line in seen:
        continue
    seen.add(line)
    out.append(line)
p.write_text('\n'.join(out) + '\n', encoding='utf-8')
print(f'Wrote {len(out)} unique lines')
