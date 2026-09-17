import difflib

f1_path = r"d:\pagespeed\hi\facebook\facebook-ver10.49\cron\publish_worker.php"
f2_path = r"d:\pagespeed\hi\facebook\cron\publish_worker.php"

with open(f1_path, 'r', encoding='utf-8', errors='ignore') as f1, \
     open(f2_path, 'r', encoding='utf-8', errors='ignore') as f2:
    lines1 = f1.readlines()
    lines2 = f2.readlines()

diff = list(difflib.unified_diff(lines1, lines2, fromfile='ver10.49/publish_worker.php', tofile='current/publish_worker.php', n=2))

with open(r"d:\pagespeed\hi\facebook\scratch\diff_output.txt", "w", encoding="utf-8") as fout:
    for line in diff:
        fout.write(line)

print(f"Total diff lines: {len(diff)}")
