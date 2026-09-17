---
description: Hướng dẫn tự động đẩy code lên Github (Push to Github)
---

Khi user yêu cầu cập nhật code lên Github hoặc có chỉnh sửa mới cần đưa lên kho lưu trữ, bạn (AI) hãy tự động thực thi chuỗi các lệnh sau theo tuần tự:

// turbo
1. Chạy lệnh `git status` để xem có bao nhiêu file bị sửa đổi / được tạo mới.

2. Hỏi User về nội dung cập nhật: "Bạn muốn đặt ghi chú (Commit message) cho bản nâng cấp này là gì?". **NẾU** user đã nói sẵn những gì họ vừa sửa trong ngoặc hoặc câu lệnh của họ (Ví dụ: "Đẩy code lên git đi, nãy thêm tính năng X"), hãy BỎ QUA câu hỏi này, AI tự phân tích và tạo một câu lệnh Commit Tiếng Anh cực kỳ súc tích.

// turbo-all
3. Chạy lệnh `git add .` để đưa tất cả thay đổi vào bản nháp.

4. Chạy lệnh `git commit -m "[Thêm thông điệp commit AI tự nghĩ ra hoặc User cấp]"` (Ví dụ: `git commit -m "Fix web-ajax timeout and add update script"`).

5. Chạy lệnh `git push` để bắn dữ liệu cập nhật thẳng lên GitHub origin.

6. Nếu lệnh Push báo lỗi do sai lệch lịch sử (non-fast-forward), tự động chạy `git pull --rebase` rồi `git push` lại.

7. Báo cáo thành công cho người dùng! Nhớ nhắc nhẹ họ rằng: "Code đã lên GitHub, giờ bạn chỉ việc quay lại màn hình Terminal của VPS và gõ `bash /opt/facebook-automation/update.sh` để VPS kéo bản cập nhật là xong nhé!"
