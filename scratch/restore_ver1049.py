import shutil

src_worker = r"d:\pagespeed\hi\facebook\facebook-ver10.49\cron\publish_worker.php"
dst_worker = r"d:\pagespeed\hi\facebook\cron\publish_worker.php"

src_start = r"d:\pagespeed\hi\facebook\facebook-ver10.49\cron\start_publish.php"
dst_start = r"d:\pagespeed\hi\facebook\cron\start_publish.php"

shutil.copyfile(src_worker, dst_worker)
shutil.copyfile(src_start, dst_start)

print("Restored cron/publish_worker.php and cron/start_publish.php from ver10.49 successfully!")
