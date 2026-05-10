from pathlib import Path
p = Path(r'C:\Users\Administrator\Downloads\sportssync (5).sql')
text = p.read_text(encoding='utf-8', errors='replace')
lines = text.splitlines()
for idx, line in enumerate(lines, start=1):
    if 'Indexes for table `users`' in line:
        print('start', idx)
        for l in lines[idx:idx+20]:
            print(l)
        break
