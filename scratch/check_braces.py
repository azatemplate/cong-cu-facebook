with open(r'd:\pagespeed\hi\facebook\cron\publish_worker.php', 'r', encoding='utf-8') as f:
    lines = f.readlines()

stack = []

for idx, line in enumerate(lines, 1):
    clean_line = ""
    i = 0
    in_str = None
    escaped = False
    
    while i < len(line):
        ch = line[i]
        if escaped:
            escaped = False
            i += 1
            continue
        if ch == '\\':
            escaped = True
            i += 1
            continue
        if in_str:
            if ch == in_str:
                in_str = None
            i += 1
            continue
        if ch in ("'", '"'):
            in_str = ch
            i += 1
            continue
        if ch == '/' and i + 1 < len(line) and line[i+1] == '/':
            break
        if ch == '#':
            break
            
        if ch == '{':
            stack.append((idx, line.strip()))
        elif ch == '}':
            if stack:
                opened_line, opened_code = stack.pop()
                if opened_line == 1318 or idx > 3750 or opened_line > 3750:
                    print(f"Closed {{ from line {opened_line} ({opened_code[:30]}...) at line {idx}: {line.strip()}")
            else:
                print(f"Extra closing brace '}}' at line {idx}: {line.strip()}")
        i += 1

print("\n--- UNCLOSED STACK AT END ---")
for line_num, code in stack:
    print(f"Line {line_num}: {code}")
