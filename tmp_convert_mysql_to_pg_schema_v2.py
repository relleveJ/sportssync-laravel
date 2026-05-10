from pathlib import Path
import re

input_path = Path(r'C:\Users\Administrator\Downloads\sportssync (5).sql')
output_path = Path('sportssync_postgres_schema.sql')
text = input_path.read_text(encoding='utf-8', errors='replace')
lines = text.splitlines()

create_blocks = []
alter_blocks = []
current = []
mode = None
for line in lines:
    stripped = line.strip()
    if stripped.upper().startswith('CREATE TABLE'):
        mode = 'create'
        current = [line]
        continue
    if stripped.upper().startswith('ALTER TABLE'):
        mode = 'alter'
        current = [line]
        continue
    if mode in ('create', 'alter'):
        current.append(line)
        if stripped.endswith(';'):
            if mode == 'create':
                create_blocks.append('\n'.join(current))
            else:
                alter_blocks.append('\n'.join(current))
            mode = None
            current = []

print(f'Found {len(create_blocks)} CREATE TABLE blocks and {len(alter_blocks)} ALTER TABLE blocks')

auto_increment_columns = {}
for block in alter_blocks:
    if re.search(r'\bMODIFY\b.*\bAUTO_INCREMENT\b', block, flags=re.I):
        header = block.splitlines()[0]
        table_match = re.search(r'ALTER TABLE [`\"]?([^`\"\s]+)[`\"]?', header)
        if not table_match:
            continue
        table = table_match.group(1)
        for line in block.splitlines()[1:]:
            m = re.search(r'MODIFY [`\"]?([^`\"\s]+)[`\"]?\s+([^,;]+AUTO_INCREMENT[^,;]*)(?:,|;|$)', line, flags=re.I)
            if m:
                col = m.group(1)
                type_part = m.group(2)
                if table not in auto_increment_columns:
                    auto_increment_columns[table] = {}
                if re.search(r'\bbigint\b', type_part, flags=re.I):
                    auto_increment_columns[table][col] = 'bigserial'
                else:
                    auto_increment_columns[table][col] = 'serial'


def quote_ident(name: str) -> str:
    name = name.strip('`" ')
    return f'"{name}"'


used_index_names = set()

def make_unique_index_name(base_name: str, table_name: str, cols: str) -> str:
    name = base_name.strip('`" ')
    if name in used_index_names:
        sanitized_cols = '_'.join(
            re.sub(r'[^a-z0-9]+', '_', col.strip(' `"').lower())
            for col in cols.split(',')
        )
        candidate = f'idx_{table_name.strip("\"").lower()}_{sanitized_cols}'
        candidate = re.sub(r'__+', '_', candidate).strip('_')
        if not candidate:
            candidate = f'idx_{table_name.strip("\"").lower()}'
        if candidate in used_index_names:
            suffix = 1
            while f'{candidate}_{suffix}' in used_index_names:
                suffix += 1
            candidate = f'{candidate}_{suffix}'
        used_index_names.add(candidate)
        return quote_ident(candidate)
    used_index_names.add(name)
    return quote_ident(name)


def normalize_type(raw_type: str, auto_increment: bool = False) -> str:
    raw = raw_type.strip()
    raw_lower = raw.lower()
    raw = re.sub(r'\s+', ' ', raw)

    if raw_lower.startswith('tinyint('):
        return 'smallint'
    if raw_lower.startswith('tinyint'):
        return 'smallint'
    if raw_lower.startswith('smallint'):
        return 'smallint'
    if raw_lower.startswith('mediumint'):
        return 'integer'
    if raw_lower.startswith('int(') or raw_lower == 'int' or raw_lower.startswith('integer'):
        return 'serial' if auto_increment else 'integer'
    if raw_lower.startswith('bigint(') or raw_lower == 'bigint':
        return 'bigserial' if auto_increment else 'bigint'
    if raw_lower.startswith('enum(') or raw_lower.startswith('set('):
        return 'text'
    if raw_lower.startswith('varchar(') or raw_lower.startswith('char('):
        return raw_lower
    if raw_lower.startswith('datetime'):
        return 'timestamp'
    if raw_lower.startswith('timestamp'):
        return 'timestamp'
    if raw_lower.startswith('double'):
        return 'double precision'
    if raw_lower.startswith('float(') or raw_lower == 'float':
        return 'real'
    if raw_lower.startswith('decimal(') or raw_lower.startswith('numeric('):
        return raw_lower
    if raw_lower.startswith('blob') or raw_lower.startswith('mediumblob') or raw_lower.startswith('longblob'):
        return 'bytea'
    if raw_lower.startswith('longtext') or raw_lower.startswith('mediumtext') or raw_lower.startswith('tinytext'):
        return 'text'
    if raw_lower.startswith('text'):
        return 'text'
    return raw


def split_top_level_commas(s: str) -> list[str]:
    parts = []
    current = ''
    depth = 0
    in_single = False
    in_double = False
    prev = ''
    for ch in s:
        if ch == "'" and prev != '\\' and not in_double:
            in_single = not in_single
        elif ch == '"' and prev != '\\' and not in_single:
            in_double = not in_double
        elif ch == '(' and not in_single and not in_double:
            depth += 1
        elif ch == ')' and not in_single and not in_double:
            depth = max(depth - 1, 0)
        if ch == ',' and depth == 0 and not in_single and not in_double:
            parts.append(current)
            current = ''
        else:
            current += ch
        prev = ch
    if current:
        parts.append(current)
    return parts


def extract_parenthesized(s: str, start: int) -> tuple[str, int]:
    assert s[start] == '('
    depth = 0
    content = ''
    i = start
    in_single = False
    in_double = False
    prev = ''
    while i < len(s):
        ch = s[i]
        if ch == "'" and prev != '\\' and not in_double:
            in_single = not in_single
        elif ch == '"' and prev != '\\' and not in_single:
            in_double = not in_double
        if ch == '(' and not in_single and not in_double:
            depth += 1
            if depth > 1:
                content += ch
        elif ch == ')' and not in_single and not in_double:
            depth -= 1
            if depth == 0:
                return content, i
            content += ch
        else:
            if depth >= 1:
                content += ch
        prev = ch
        i += 1
    return content, i


def convert_mysql_concat(expr: str, start: int) -> tuple[str, int]:
    prefix = expr[start:start+6]
    i = start + 6
    while i < len(expr) and expr[i].isspace():
        i += 1
    if i >= len(expr) or expr[i] != '(':
        return prefix, i
    inner, end = extract_parenthesized(expr, i)
    parts = split_top_level_commas(inner)
    converted_parts = [convert_mysql_expression(part.strip()) for part in parts]
    return f'({" || ".join(converted_parts)})', end + 1


def convert_mysql_if(expr: str, start: int) -> tuple[str, int]:
    prefix = expr[start:start+2]
    i = start + 2
    while i < len(expr) and expr[i].isspace():
        i += 1
    if i >= len(expr) or expr[i] != '(':
        return prefix, i
    inner, end = extract_parenthesized(expr, i)
    parts = split_top_level_commas(inner)
    converted_parts = [convert_mysql_expression(part.strip()) for part in parts]
    if len(converted_parts) >= 3:
        condition = converted_parts[0]
        true_expr = converted_parts[1]
        false_expr = ','.join(converted_parts[2:])
        return f'(CASE WHEN {condition} THEN {true_expr} ELSE {false_expr} END)', end + 1
    return f'IF({inner})', end + 1


def convert_mysql_expression(expr: str) -> str:
    expr = expr.replace('`', '"')
    expr = re.sub(r'\bIFNULL\s*\(', 'COALESCE(', expr, flags=re.I)
    result = ''
    i = 0
    while i < len(expr):
        if expr[i:i+2].lower() == 'if':
            replacement, new_i = convert_mysql_if(expr, i)
            result += replacement
            i = new_i
            continue
        if expr[i:i+6].lower() == 'concat':
            replacement, new_i = convert_mysql_concat(expr, i)
            result += replacement
            i = new_i
            continue
        result += expr[i]
        i += 1
    return result


def strip_mysql_options(rest: str) -> str:
    rest = re.sub(r'CHARACTER SET \w+', '', rest, flags=re.I)
    rest = re.sub(r'COLLATE [^\s,]+', '', rest, flags=re.I)
    rest = re.sub(r'ON UPDATE CURRENT_TIMESTAMP\(\)', '', rest, flags=re.I)
    rest = re.sub(r'ON UPDATE CURRENT_TIMESTAMP\s*\(\s*\)', '', rest, flags=re.I)
    rest = re.sub(r'CURRENT_TIMESTAMP\s*\(\s*\)', 'CURRENT_TIMESTAMP', rest, flags=re.I)
    rest = re.sub(r'current_timestamp\s*\(\s*\)', 'CURRENT_TIMESTAMP', rest, flags=re.I)
    rest = re.sub(r'DEFAULT NULL', '', rest, flags=re.I)
    rest = re.sub(r'UNSIGNED', '', rest, flags=re.I)
    rest = re.sub(r'AUTO_INCREMENT', '', rest, flags=re.I)
    rest = re.sub(r'COMMENT\s+(?:\'[^\']*\'|\"[^\"]*\")', '', rest, flags=re.I)
    rest = rest.replace('`', '"')
    rest = convert_mysql_expression(rest)
    rest = re.sub(r'\s+', ' ', rest).strip()
    return rest


def parse_column_definition(line: str, table_name: str) -> str:
    column_match = re.match(r'^(\s*)([`\"]?)([^`\"\s]+)\2\s+(.*)$', line)
    if not column_match:
        return line
    indent, _, name, rest = column_match.groups()
    column_name = quote_ident(name)
    rest = rest.rstrip(',').rstrip()

    auto_inc = bool(re.search(r'\bAUTO_INCREMENT\b', rest, flags=re.I))
    if table_name in auto_increment_columns and name in auto_increment_columns[table_name]:
        auto_inc = True
        serial_type = auto_increment_columns[table_name][name]
    else:
        serial_type = None

    rest = strip_mysql_options(rest)

    if rest.lower().startswith(('enum(', 'set(')):
        depth = 0
        token = ''
        i = 0
        while i < len(rest):
            token += rest[i]
            if rest[i] == '(':
                depth += 1
            elif rest[i] == ')':
                depth -= 1
                if depth == 0:
                    i += 1
                    break
            i += 1
        type_token = token.strip()
        remainder = rest[i:].strip()
    else:
        m = re.match(r'^(\w+(?:\([^)]*\))?)(.*)$', rest)
        if not m:
            type_token = rest
            remainder = ''
        else:
            type_token, remainder = m.groups()
            remainder = remainder.strip()

    if serial_type:
        pg_type = serial_type
    else:
        pg_type = normalize_type(type_token, auto_increment=auto_inc)
    if auto_inc and pg_type in ('serial', 'bigserial'):
        remainder = re.sub(r'NOT NULL', '', remainder, flags=re.I).strip()
    remainder = re.sub(r'\s+', ' ', remainder).strip()
    if remainder:
        converted = f'{indent}{column_name} {pg_type} {remainder}'
    else:
        converted = f'{indent}{column_name} {pg_type}'
    if line.rstrip().endswith(','):
        converted += ','
    return converted


def convert_create_block(block: str) -> str:
    out_lines = []
    table_name = ''
    for line in block.splitlines():
        stripped = line.strip()
        if stripped.upper().startswith('CREATE TABLE'):
            table_name = re.search(r'CREATE TABLE [`\"]?([^`\"\s(]+)[`\"]?', line, flags=re.I).group(1)
            out_lines.append(f'CREATE TABLE IF NOT EXISTS {quote_ident(table_name)} (')
            continue
        if stripped.startswith(')'):
            out_lines.append(');')
            continue
        if stripped.upper().startswith('KEY ') or stripped.upper().startswith('UNIQUE KEY') or stripped.upper().startswith('PRIMARY KEY'):
            continue
        if stripped.startswith('--') or stripped == '':
            continue
        if re.match(r'^[`"].*', stripped):
            out_lines.append(parse_column_definition(line, table_name))
            continue
        out_lines.append(line)
    return '\n'.join(out_lines)


def normalize_index_column(col: str) -> str:
    col = col.strip(' `"')
    col = re.sub(r'\(\d+\)$', '', col)
    return quote_ident(col)


def parse_alter_block(block: str):
    lines = block.splitlines()
    header = lines[0]
    table_match = re.search(r'ALTER TABLE [`\"]?([^`\"\s]+)[`\"]?', header)
    if not table_match:
        return []
    table_name = quote_ident(table_match.group(1))
    rest = '\n'.join(lines[1:])
    rest = rest.strip()
    if rest.endswith(';'):
        rest = rest[:-1]
    if not re.search(r'ADD PRIMARY KEY|ADD UNIQUE KEY|ADD UNIQUE INDEX|ADD KEY|ADD INDEX|ADD CONSTRAINT', rest, flags=re.I):
        return []
    parts = []
    current = ''
    depth = 0
    for char in rest:
        if char == '(':
            depth += 1
        elif char == ')':
            depth = max(depth - 1, 0)
        if char == ',' and depth == 0:
            parts.append(current.strip())
            current = ''
        else:
            current += char
    if current.strip():
        parts.append(current.strip())

    results = []
    for part in parts:
        part = part.strip().rstrip(',')
        if not part:
            continue
        if part.upper().startswith('ADD PRIMARY KEY'):
            cols = re.search(r'ADD PRIMARY KEY \((.*)\)', part, flags=re.I).group(1)
            cols = ', '.join(normalize_index_column(c) for c in cols.split(','))
            results.append(f'ALTER TABLE {table_name} ADD PRIMARY KEY ({cols});')
        elif part.upper().startswith('ADD UNIQUE KEY') or part.upper().startswith('ADD UNIQUE INDEX'):
            cols = re.search(r'\((.*)\)', part, flags=re.I).group(1)
            cols = ', '.join(normalize_index_column(c) for c in cols.split(','))
            results.append(f'ALTER TABLE {table_name} ADD UNIQUE ({cols});')
        elif part.upper().startswith('ADD KEY') or part.upper().startswith('ADD INDEX'):
            idx_match = re.search(r'ADD (?:KEY|INDEX) [`\"]?([^`\"\s]+)[`\"]? \((.*)\)', part, flags=re.I)
            if idx_match:
                raw_idx_name = idx_match.group(1)
                cols = ', '.join(normalize_index_column(c) for c in idx_match.group(2).split(','))
                idx_name = make_unique_index_name(raw_idx_name, table_name, idx_match.group(2))
                results.append(f'CREATE INDEX {idx_name} ON {table_name} ({cols});')
        elif 'FOREIGN KEY' in part.upper():
            fk_match = re.search(r'ADD CONSTRAINT [`\"]?([^`\"\s]+)[`\"]? FOREIGN KEY \(([^\)]*)\) REFERENCES [`\"]?([^`\"\s]+)[`\"]? \(([^\)]*)\)(.*)', part, flags=re.I)
            if fk_match:
                cname = quote_ident(fk_match.group(1))
                cols = ', '.join(normalize_index_column(c) for c in fk_match.group(2).split(','))
                ref_table = quote_ident(fk_match.group(3))
                ref_cols = ', '.join(normalize_index_column(c) for c in fk_match.group(4).split(','))
                tail = fk_match.group(5).strip()
                if tail:
                    tail = ' ' + tail
                results.append(f'ALTER TABLE {table_name} ADD CONSTRAINT {cname} FOREIGN KEY ({cols}) REFERENCES {ref_table} ({ref_cols}){tail};')
        else:
            continue
    return results

output_lines = []
for block in create_blocks:
    output_lines.append(convert_create_block(block))
    output_lines.append('')
for block in alter_blocks:
    for stmt in parse_alter_block(block):
        output_lines.append(stmt)

output_path.write_text('\n'.join(output_lines).strip() + '\n', encoding='utf-8')
print(f'Written converted schema to {output_path}')
