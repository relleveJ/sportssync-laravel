from pathlib import Path
from collections import Counter
import re
path = Path(r'c:\Users\Administrator\Desktop\XAMPP MAIN FILE\htdocs\sportssync-laravel\sportssync_postgres_schema.sql')
text = path.read_text(encoding='utf-8')
lines = [l.strip() for l in text.splitlines() if l.strip().startswith('CREATE INDEX')]
names = [re.match(r'CREATE INDEX "([^"]+)"', l).group(1) for l in lines if re.match(r'CREATE INDEX "([^"]+)"', l)]
for name, count in Counter(names).items():
    if count > 1:
        print(name, count)
print('total create indexes', len(names))
