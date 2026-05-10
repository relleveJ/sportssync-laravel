from pathlib import Path
path = Path(r'c:\Users\Administrator\Desktop\XAMPP MAIN FILE\htdocs\sportssync-laravel\tmp_convert_mysql_to_pg_schema_v2.py')
text = path.read_text(encoding='utf-8')
text = text.replace("candidate = f'idx_{table_name.strip(\"\").lower()}_{sanitized_cols}'", "table_name_clean = table_name.strip('"').lower()\n        candidate = f'idx_{table_name_clean}_{sanitized_cols}'")
text = text.replace("candidate = f'idx_{table_name.strip(\"\").lower()}'", "candidate = f'idx_{table_name_clean}'")
path.write_text(text, encoding='utf-8')
