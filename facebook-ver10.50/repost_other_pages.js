/**
 * Repost Other Pages Logic (Graph API Token)
 * Allows scanning posts from arbitrary source Page IDs, selecting posts,
 * applying multiple keyword find & replace pairs (FIRST), AI rewriting (SECOND),
 * downloading media, and publishing immediately or scheduling to target Page(s).
 */

window.RepostOtherPages = (function () {
    let scannedPosts = [];
    let isProcessing = false;

    // Helper to set status message in modal
    const setModalStatus = (msg, type = "info") => {
        const statusEl = document.getElementById("repostScanStatus");
        if (!statusEl) return;

        let color = "#64748b";
        if (type === "error") color = "#ef4444";
        else if (type === "success") color = "#10b981";
        else if (type === "warning") color = "#f59e0b";

        statusEl.style.color = color;
        statusEl.textContent = msg;
    };

    // Helper to get EAA token
    const getEAAToken = async () => {
        if (typeof window.getPagespeedTokens === 'function') {
            try {
                const t = await window.getPagespeedTokens();
                if (t.custom_token && t.custom_token.startsWith("EAA")) return t.custom_token;
                if (t.token && t.token.startsWith("EAA")) return t.token;
            } catch (e) {
                console.error("getEAAToken error:", e);
            }
        }
        return null;
    };

    // Helper: Get Selected / Checked Target Pages
    const getCheckedPages = () => {
        let pages = [];
        if (typeof window.getSelectedPages === 'function') {
            try { pages = window.getSelectedPages(); } catch (e) { }
        }

        if (!pages || pages.length === 0) {
            const checkedBoxes = document.querySelectorAll('#tableBody input[type="checkbox"]:checked, tbody input[type="checkbox"]:checked');
            pages = Array.from(checkedBoxes).map(cb => {
                const row = cb.closest('tr');
                return {
                    id: row?.dataset?.id,
                    name: row?.dataset?.name
                };
            }).filter(p => p && p.id);
        }

        if ((!pages || pages.length === 0) && window.selectedRowData) {
            pages = [window.selectedRowData];
        }

        return pages || [];
    };

    // Helper: Convert image/video URL to File object (using PHP cURL proxy for 100% binary-safe downloads)
    const urlToFile = async (url, filename, defaultType = 'image/jpeg') => {
        if (!url) return null;

        // Method 1: Use PHP cURL Proxy (bypasses CORS & prevents UTF-8 binary corruption)
        try {
            const proxyUrl = `index.php?proxy_url=${encodeURIComponent(url)}&filename=${encodeURIComponent(filename)}`;
            const res = await fetch(proxyUrl);
            if (res.ok) {
                const blob = await res.blob();
                if (blob && blob.size > 500) {
                    console.log(`[Repost] PHP proxy download success for ${filename} (${blob.size} bytes)`);
                    return new File([blob], filename, { type: blob.type || defaultType });
                }
            }
        } catch (e) {
            console.warn(`[Repost] PHP proxy fetch failed for ${url}, trying direct fetch fallback...`, e);
        }

        // Method 2: Direct CORS fetch
        try {
            const res = await fetch(url);
            if (res.ok) {
                const blob = await res.blob();
                if (blob && blob.size > 500) {
                    return new File([blob], filename, { type: blob.type || defaultType });
                }
            }
        } catch (e) {
            console.warn(`[Repost] Direct fetch failed for ${url}:`, e);
        }

        // Method 3: For images, try Canvas draw fallback
        if (defaultType.startsWith('image')) {
            try {
                const blob = await new Promise((resolve, reject) => {
                    const img = new Image();
                    img.crossOrigin = 'Anonymous';
                    img.onload = () => {
                        try {
                            const canvas = document.createElement('canvas');
                            canvas.width = img.naturalWidth || img.width || 800;
                            canvas.height = img.naturalHeight || img.height || 600;
                            const ctx = canvas.getContext('2d');
                            ctx.drawImage(img, 0, 0);
                            canvas.toBlob((b) => {
                                if (b && b.size > 100) resolve(b);
                                else reject('Canvas blob null');
                            }, defaultType, 0.95);
                        } catch (err) {
                            reject(err);
                        }
                    };
                    img.onerror = (err) => reject('Image element failed to load');
                    img.src = url;
                });
                if (blob) return new File([blob], filename, { type: defaultType });
            } catch (e) {
                console.warn(`[Repost] Canvas fallback failed for ${url}:`, e);
            }
        }

        return null;
    };

    // Helper to remove all hashtags
    const removeHashtags = (str) => {
        if (!str) return "";
        return str.replace(/#[^\s#]+/gi, "").replace(/\s+/g, " ").trim();
    };

    // Helper to remove all URLs (http/https)
    const removeLinks = (str) => {
        if (!str) return "";
        return str.replace(/https?:\/\/[^\s]+/gi, "").replace(/\s+/g, " ").trim();
    };

    // Helper to add a Find & Replace row dynamically
    const addFindReplaceRow = (findVal = "", replaceVal = "") => {
        const container = document.getElementById("repostFindReplaceContainer");
        if (!container) return;

        const rowId = `fr_row_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`;

        const row = document.createElement("div");
        row.id = rowId;
        row.className = "repost-fr-row";
        row.style.cssText = "display: flex; gap: 10px; align-items: center;";
        row.innerHTML = `
            <div style="flex: 1;">
                <input type="text" class="repost-find-kw" placeholder="Từ khóa tìm..." value="${findVal}" style="width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-size: 12px;">
            </div>
            <div style="flex: 1;">
                <input type="text" class="repost-replace-kw" placeholder="Thay thế bằng..." value="${replaceVal}" style="width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-size: 12px;">
            </div>
            <button type="button" class="btn-del-fr-row" style="background: #fee2e2; color: #ef4444; border: none; padding: 6px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; font-weight: bold;" title="Xóa dòng này">✕</button>
        `;

        row.querySelector(".btn-del-fr-row").addEventListener("click", () => row.remove());
        container.appendChild(row);
    };

    // Helper to get Page Access Token for a specific pageId if available in workspace
    const getPageAccessToken = async (pageId) => {
        // 1. Check window.allPagesData or window.allPages
        const pages = window.allPagesData || window.allPages || [];
        if (Array.isArray(pages)) {
            const found = pages.find(p => String(p.id) === String(pageId));
            if (found && (found.access_token || found.token)) {
                console.log(`[Repost] Found cached Page Access Token for ${pageId}`);
                return found.access_token || found.token;
            }
        }
        // 2. Try fetching page access_token via User Token
        const userToken = await getEAAToken();
        if (userToken) {
            try {
                const url = `https://graph.facebook.com/v24.0/${pageId}?fields=access_token&access_token=${encodeURIComponent(userToken)}`;
                const res = await (window.extensionFetch || fetch)(url).then(r => r.json());
                if (res.access_token) {
                    console.log(`[Repost] Fetched Page Access Token for ${pageId} via Graph API`);
                    return res.access_token;
                }
            } catch (e) {}
        }
        return null;
    };

    // Scan posts from source Page IDs via Graph API Token
    const scanPosts = async () => {
        const sourceText = document.getElementById("repostSourcePageIds")?.value || "";
        const rawIds = sourceText.split(/\r?\n/).map(s => s.trim()).filter(s => s);

        if (rawIds.length === 0) {
            setModalStatus("⚠️ Vui lòng nhập ít nhất 1 Page ID nguồn!", "error");
            return;
        }

        // Helper to extract clean Page ID from input or URL
        const cleanPageId = (str) => {
            if (!str) return "";
            str = str.trim();
            const matchNum = str.match(/(?:profile\.php\?id=|facebook\.com\/)(\d+)/i);
            if (matchNum) return matchNum[1];
            return str.replace(/https?:\/\/[^\/]+\//i, '').replace(/\/+$/, '');
        };

        const sourceIds = rawIds.map(cleanPageId).filter(s => s);

        const limit = parseInt(document.getElementById("repostLimitPerPage")?.value || 10);
        const formatFilter = document.getElementById("repostFormatFilter")?.value || "all";
        const rangeFilter = document.getElementById("repostRangeFilter")?.value || "all";

        let minTimestamp = 0;
        const now = Date.now();
        if (rangeFilter === "today") {
            minTimestamp = new Date().setHours(0, 0, 0, 0);
        } else if (rangeFilter === "1h") {
            minTimestamp = now - 3600 * 1000;
        } else if (rangeFilter === "3d") {
            minTimestamp = now - 3 * 86400 * 1000;
        } else if (rangeFilter === "7d") {
            minTimestamp = now - 7 * 86400 * 1000;
        } else if (rangeFilter === "30d") {
            minTimestamp = now - 30 * 86400 * 1000;
        }

        const token = await getEAAToken();

        if (!token) {
            setModalStatus("⚠️ Không tìm thấy Token EAA để quét! Vui lòng kiểm tra lại đăng nhập Extension.", "error");
            return;
        }

        const btnScan = document.getElementById("btnScanRepostPosts");
        if (btnScan) {
            btnScan.disabled = true;
            btnScan.innerHTML = `⏳ Đang Quét...`;
        }

        const listContainer = document.getElementById("repostPostsList");

        setModalStatus("⏳ Đang kết nối Facebook Graph API để quét bài...", "info");
        if (listContainer) {
            listContainer.innerHTML = `
                <div style="padding: 30px; text-align: center; color: #1877f2; font-weight: 600;">
                    <div style="font-size: 24px; margin-bottom: 8px;">⏳</div>
                    Đang quét bài viết từ Facebook... Vui lòng chờ trong giây lát.
                </div>
            `;
        }

        scannedPosts = [];
        let totalApiPostsFound = 0;
        let lastApiError = null;

        try {
            for (const pageId of sourceIds) {
                setModalStatus(`⏳ Đang quét Page ID: ${pageId}...`, "info");

                const endpoints = ['posts', 'feed', 'published_posts'];
                let res = null;
                let successRes = null;

                for (const ep of endpoints) {
                    const url = `https://graph.facebook.com/v24.0/${pageId}/${ep}?fields=id,message,created_time,full_picture,attachments{media,media_type,subattachments,target,type,url}&limit=${limit}&access_token=${encodeURIComponent(token)}`;
                    try {
                        const r = await (window.extensionFetch || fetch)(url, { method: "GET" }).then(resObj => resObj.json());
                        if (r.data && Array.isArray(r.data)) {
                            successRes = r;
                            break;
                        } else if (r.error) {
                            res = r;
                            if (r.error.code !== 100 && r.error.code !== 210) break;
                        }
                    } catch (errEp) {
                        console.warn(`[Repost] Fetch endpoint ${ep} failed for ${pageId}:`, errEp);
                    }
                }

                let finalRes = successRes || res;

                // Auto retry with Page Access Token if Error 210 occurs
                if (!successRes && finalRes?.error?.code === 210) {
                    console.log(`[Repost] Error 210 for ${pageId}. Retrying with Page Access Token...`);
                    const pageTok = await getPageAccessToken(pageId);
                    if (pageTok) {
                        for (const ep of endpoints) {
                            const url = `https://graph.facebook.com/v24.0/${pageId}/${ep}?fields=id,message,created_time,full_picture,attachments{media,media_type,subattachments,target,type,url}&limit=${limit}&access_token=${encodeURIComponent(pageTok)}`;
                            try {
                                const r = await (window.extensionFetch || fetch)(url, { method: "GET" }).then(resObj => resObj.json());
                                if (r.data && Array.isArray(r.data)) {
                                    successRes = r;
                                    finalRes = r;
                                    break;
                                }
                            } catch (eRetry) {}
                        }
                    }
                }

                if (!successRes && finalRes?.error) {
                    console.warn(`[Repost] Graph API Error for ${pageId}:`, finalRes.error);
                    if (finalRes.error.code === 210) {
                        lastApiError = `Page ID ${pageId}: Facebook yêu cầu Page Access Token hoặc quyền Quản trị viên để đọc bài viết (Mã lỗi 210). Nếu đây là Page của bạn, hãy chọn Page trong danh sách chính trước khi quét.`;
                    } else if (finalRes.error.code === 100) {
                        lastApiError = `ID "${pageId}" không phải là Fanpage công khai (Mã lỗi 100). Graph API chỉ hỗ trợ reup từ Fanpage. ID này có thể là Nick cá nhân hoặc Nhóm.`;
                    } else {
                        lastApiError = `Page ID ${pageId}: ${finalRes.error.message} (Mã lỗi: ${finalRes.error.code})`;
                    }
                    continue;
                }

                if (successRes && successRes.data && Array.isArray(successRes.data)) {
                    totalApiPostsFound += successRes.data.length;

                    successRes.data.forEach(post => {
                        // Date filter check
                        if (minTimestamp > 0 && post.created_time) {
                            const pTime = new Date(post.created_time).getTime();
                            if (pTime < minTimestamp) return;
                        }

                        const images = [];
                        const videos = [];

                        const attachList = post.attachments?.data || [];
                        attachList.forEach(att => {
                            if (att.media?.image?.src) {
                                if (att.media_type === 'video' || att.type?.includes('video')) {
                                    if (att.media.source) videos.push(att.media.source);
                                    else images.push(att.media.image.src);
                                } else {
                                    images.push(att.media.image.src);
                                }
                            }
                            if (att.subattachments?.data) {
                                att.subattachments.data.forEach(sub => {
                                    if (sub.media?.image?.src) {
                                        if (sub.media_type === 'video' || sub.type?.includes('video')) {
                                            if (sub.media.source) videos.push(sub.media.source);
                                            else images.push(sub.media.image.src);
                                        } else {
                                            images.push(sub.media.image.src);
                                        }
                                    }
                                });
                            }
                        });

                        if (images.length === 0 && videos.length === 0 && post.full_picture) {
                            images.push(post.full_picture);
                        }

                        const finalImages = [...new Set(images)];
                        const finalVideos = [...new Set(videos)];

                        // Format filter check
                        if (formatFilter === "photo" && finalImages.length === 0) return;
                        if (formatFilter === "video" && finalVideos.length === 0) return;
                        if (formatFilter === "text" && (finalImages.length > 0 || finalVideos.length > 0)) return;

                        scannedPosts.push({
                            id: post.id,
                            sourcePageId: pageId,
                            message: post.message || "",
                            createdTime: post.created_time,
                            thumbnail: post.full_picture || (finalImages[0] || ""),
                            images: finalImages,
                            videos: finalVideos
                        });
                    });
                }
            }
        } catch (e) {
            console.error(`Error scanning pages:`, e);
            lastApiError = `Lỗi mạng/kết nối: ${e.message}`;
        } finally {
            if (btnScan) {
                btnScan.disabled = false;
                btnScan.innerHTML = `<span>🔍 Quét Bài Viết</span>`;
            }
        }

        if (scannedPosts.length === 0) {
            if (lastApiError) {
                setModalStatus(`❌ Facebook báo lỗi: ${lastApiError}`, "error");
            } else if (totalApiPostsFound > 0) {
                setModalStatus(`⚠️ Đã tìm thấy ${totalApiPostsFound} bài viết từ Facebook nhưng 0 bài thỏa mãn bộ lọc (Loại bài: "${formatFilter}", Thời gian: "${rangeFilter}"). Thử chọn "Tất cả loại bài" và "Toàn bộ bài gần đây".`, "warning");
            } else {
                setModalStatus(`⚠️ Không tìm thấy bài viết nào từ Page ID nguồn. Kiểm tra xem Page ID có đúng không hoặc Page có để công khai không.`, "warning");
            }
        } else {
            setModalStatus(`✓ Tìm thấy ${scannedPosts.length} bài viết hợp lệ từ ${sourceIds.length} Page nguồn.`, "success");
        }

        renderPostsList();
    };

    // Render list of scanned posts with checkboxes
    const renderPostsList = () => {
        const container = document.getElementById("repostPostsList");
        if (!container) return;

        if (scannedPosts.length === 0) {
            container.innerHTML = '<div style="padding: 20px; text-align: center; color: #888;">Không tìm thấy bài viết nào thỏa điều kiện lọc.</div>';
            return;
        }

        let html = `
            <div style="padding: 8px 12px; background: #eaf3ff; border-bottom: 1px solid #d0e3ff; display: flex; align-items: center; justify-content: space-between;">
                <label style="font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 13px;">
                    <input type="checkbox" id="chkSelectAllRepostPosts" checked> Chọn tất cả (${scannedPosts.length} bài)
                </label>
                <span style="font-size: 12px; color: #555;">Đã lọc xong</span>
            </div>
            <div style="display: flex; flex-direction: column; gap: 10px; padding: 10px;">
        `;

        scannedPosts.forEach((post, index) => {
            const dateStr = post.createdTime ? new Date(post.createdTime).toLocaleString('vi-VN') : '';
            const msgPreview = post.message ? post.message.substring(0, 150) + (post.message.length > 150 ? '...' : '') : '(Không có nội dung chữ)';

            html += `
                <div style="display: flex; gap: 12px; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px; background: #fff; align-items: flex-start;">
                    <input type="checkbox" class="repost-post-item" data-index="${index}" checked style="margin-top: 4px;">
                    ${post.thumbnail ? `<img src="${post.thumbnail}" style="width: 70px; height: 70px; object-fit: cover; border-radius: 4px; border: 1px solid #eee;">` : '<div style="width: 70px; height: 70px; background: #f3f4f6; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 20px;">📝</div>'}
                    <div style="flex: 1; font-size: 13px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                            <span style="font-weight: 600; color: #1877f2;">Page ID: ${post.sourcePageId} | Post ID: ${post.id}</span>
                            <span style="color: #888; font-size: 12px;">${dateStr}</span>
                        </div>
                        <div style="color: #333; margin-bottom: 6px; line-height: 1.4; word-break: break-word;">${msgPreview}</div>
                        <div style="font-size: 11px; color: #059669; font-weight: 500;">
                            📸 ${post.images.length} Ảnh | 🎥 ${post.videos.length} Video
                        </div>
                    </div>
                </div>
            `;
        });

        html += `</div>`;
        container.innerHTML = html;

        const chkSelectAll = document.getElementById("chkSelectAllRepostPosts");
        if (chkSelectAll) {
            chkSelectAll.addEventListener("change", (e) => {
                const items = container.querySelectorAll(".repost-post-item");
                items.forEach(cb => cb.checked = e.target.checked);
            });
        }
    };

    // Helper to update specific row status
    const updateRowStatus = (id, msg) => {
        // Implementation omitted for brevity in snippet
    };

    // Start Reposting Process
    const startRepost = async () => {
        if (isProcessing) return;

        const container = document.getElementById("repostPostsList");
        const checkedItems = container ? Array.from(container.querySelectorAll(".repost-post-item:checked")) : [];

        if (checkedItems.length === 0) {
            setModalStatus("⚠️ Vui lòng chọn ít nhất 1 bài viết để đăng lại!", "error");
            return;
        }

        const targetPages = getCheckedPages();

        if (targetPages.length === 0) {
            setModalStatus("⚠️ Vui lòng chọn ít nhất 1 Trang đích trong bảng chính!", "error");
            return;
        }

        isProcessing = true;

        // Collect all Find & Replace pairs
        const frRows = Array.from(document.querySelectorAll("#repostFindReplaceContainer .repost-fr-row"));
        const frPairs = frRows.map(row => {
            return {
                find: row.querySelector(".repost-find-kw")?.value || "",
                replace: row.querySelector(".repost-replace-kw")?.value || ""
            };
        }).filter(p => p.find !== "");

        const useAi = document.getElementById("chkAiRewriteRepost")?.checked || false;
        const chkRemoveHashtags = document.getElementById("chkRemoveHashtags")?.checked || false;
        const chkRemoveLinks = document.getElementById("chkRemoveLinks")?.checked || false;
        const distributeMode = document.getElementById("repostDistributeMode")?.value || "all";
        const isSchedule = document.getElementById("radioScheduleRepost")?.checked || false;

        let scheduleTasks = [];
        if (isSchedule) {
            const sDate = document.getElementById("repostScheduleStartDate")?.value;
            const eDate = document.getElementById("repostScheduleEndDate")?.value;
            const slotsStr = document.getElementById("repostScheduleTimeSlots")?.value;

            if (!sDate || !eDate || !slotsStr) {
                setModalStatus("⚠️ Vui lòng nhập đầy đủ ngày bắt đầu, ngày kết thúc và mốc giờ đăng!", "error");
                isProcessing = false;
                return;
            }

            const startD = new Date(sDate);
            const endD = new Date(eDate);
            const slots = slotsStr.split(',').map(s => s.trim()).filter(s => s);

            for (let d = new Date(startD); d <= endD; d.setDate(d.getDate() + 1)) {
                for (const s of slots) {
                    const [hh, mm] = s.split(':');
                    const tsDate = new Date(d);
                    tsDate.setHours(parseInt(hh), parseInt(mm), 0, 0);
                    if (tsDate.getTime() > Date.now() + 600000) {
                        scheduleTasks.push(tsDate.getTime() / 1000);
                    }
                }
            }

            if (scheduleTasks.length === 0) {
                setModalStatus("⚠️ Không có mốc thời gian hợp lệ (thời gian phải > hiện tại 10 phút).", "error");
                isProcessing = false;
                return;
            }
        }

        const modal = document.getElementById("repostOtherPagesModal");
        if (modal) modal.style.display = "none";

        const btn = document.getElementById("btnStartRepost");
        if (btn) btn.textContent = "ĐANG ĐĂNG BÀI...";

        const selectedPosts = checkedItems.map(cb => scannedPosts[parseInt(cb.dataset.index)]).filter(p => p);

        console.log(`[Repost] Starting reposting ${selectedPosts.length} posts to ${targetPages.length} target pages (Mode: ${distributeMode})`);

        // Helper process single post push
        const processSinglePost = async (page, post, postIdx) => {
            let text = post.message || "";

            // 1. FIRST: Perform ALL Find & Replace pairs
            if (frPairs.length > 0) {
                frPairs.forEach(pair => {
                    if (pair.find) {
                        const regex = new RegExp(pair.find.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g');
                        text = text.replace(regex, pair.replace);
                    }
                });
            }

            // 2. SECOND: Remove Hashtags if enabled
            if (chkRemoveHashtags) {
                text = removeHashtags(text);
            }

            // 3. THIRD: Remove Links if enabled
            if (chkRemoveLinks) {
                text = removeLinks(text);
            }

            // 4. FOURTH: Perform AI Rewrite (if enabled)
            if (useAi && typeof window.rewriteContentWithAI === 'function') {
                updateRowStatus(page.id, `🤖 AI đang viết lại nội dung bài post ${post.id}...`);
                try {
                    text = await window.rewriteContentWithAI(text, false);
                } catch (e) {
                    console.error("AI Rewrite Error:", e);
                }
            }

            // Convert Image URLs to File objects using PHP cURL proxy
            const imageFiles = [];
            if (post.images.length > 0) {
                updateRowStatus(page.id, `📥 Đang tải ${post.images.length} ảnh...`);
                for (let i = 0; i < post.images.length; i++) {
                    const file = await urlToFile(post.images[i], `repost_img_${Date.now()}_${i}.jpg`, 'image/jpeg');
                    if (file) imageFiles.push(file);
                }
            }

            // Convert Video URLs to File objects using PHP cURL proxy
            const videoFiles = [];
            if (post.videos.length > 0) {
                updateRowStatus(page.id, `📥 Đang tải ${post.videos.length} video...`);
                for (let i = 0; i < post.videos.length; i++) {
                    const file = await urlToFile(post.videos[i], `repost_vid_${Date.now()}_${i}.mp4`, 'video/mp4');
                    if (file) videoFiles.push(file);
                }
            }

            let resPostId = null;
            // Scheduled or Instant
            if (isSchedule && scheduleTasks.length > 0) {
                const schedTs = scheduleTasks[postIdx % scheduleTasks.length];
                const dateDisplay = new Date(schedTs * 1000).toLocaleString('vi-VN');
                updateRowStatus(page.id, `📅 Đang lên lịch bài [${dateDisplay}]...`);
                if (typeof window.postFeedToFB === 'function') {
                    resPostId = await window.postFeedToFB(page, imageFiles, videoFiles, text, false, false, false, schedTs);
                }
            } else {
                updateRowStatus(page.id, `🚀 Đang đăng ngay bài viết...`);
                if (typeof window.postFeedToFB === 'function') {
                    resPostId = await window.postFeedToFB(page, imageFiles, videoFiles, text, false, false, false, null);
                }
            }
        };

        if (distributeMode === "random") {
            // Distribute mode: Pick 1 target page per post randomly or in cycle
            let pIdx = 0;
            for (const post of selectedPosts) {
                const page = targetPages[pIdx % targetPages.length];
                updateRowStatus(page.id, `⏳ Đang đăng bài ${post.id} (Phân phối xoay vòng)...`);
                await processSinglePost(page, post, pIdx);
                pIdx++;

                const delayMin = parseInt(document.getElementById("repostDelayMin")?.value || 5);
                const delayMax = parseInt(document.getElementById("repostDelayMax")?.value || 10);
                const delay = (Math.floor(Math.random() * (delayMax - delayMin + 1)) + delayMin) * 1000;
                await new Promise(r => setTimeout(r, delay));
            }
        } else {
            // All mode: Post all selected posts to each target page
            for (const page of targetPages) {
                updateRowStatus(page.id, `⏳ Đang xử lý đăng lại cho ${page.name}...`);
                let postIdx = 0;

                for (const post of selectedPosts) {
                    await processSinglePost(page, post, postIdx);
                    postIdx++;

                    const delayMin = parseInt(document.getElementById("repostDelayMin")?.value || 5);
                    const delayMax = parseInt(document.getElementById("repostDelayMax")?.value || 10);
                    const delay = (Math.floor(Math.random() * (delayMax - delayMin + 1)) + delayMin) * 1000;
                    await new Promise(r => setTimeout(r, delay));
                }

                updateRowStatus(page.id, `✅ Đã hoàn tất đăng lại!`);
            }
        }

        if (btn) btn.textContent = "BẮT ĐẦU ĐĂNG LẠI";
        isProcessing = false;
        updateRowStatus("all", "✅ Hoàn tất tiến trình đăng lại bài viết!");
    };

    // Public Open Modal Launcher
    const openModal = () => {
        initListeners();
        const modal = document.getElementById("repostOtherPagesModal");
        if (modal) {
            modal.style.display = "flex";
            const targetCountEl = document.getElementById("repostTargetCountDisplay");
            const targetPages = getCheckedPages();
            if (targetCountEl) targetCountEl.textContent = `${targetPages.length} Trang`;
        }
    };

    // Safe Event Listeners Binder
    const initListeners = () => {
        const btnScan = document.getElementById("btnScanRepostPosts");
        if (btnScan && !btnScan.dataset.bound) {
            btnScan.dataset.bound = "true";
            btnScan.addEventListener("click", scanPosts);
        }

        const btnStart = document.getElementById("btnStartRepost");
        if (btnStart && !btnStart.dataset.bound) {
            btnStart.dataset.bound = "true";
            btnStart.addEventListener("click", startRepost);
        }

        const closeBtn = document.getElementById("closeRepostOtherPagesModal");
        if (closeBtn && !closeBtn.dataset.bound) {
            closeBtn.dataset.bound = "true";
            closeBtn.addEventListener("click", () => {
                const modal = document.getElementById("repostOtherPagesModal");
                if (modal) modal.style.display = "none";
            });
        }

        // Dynamic Add Find & Replace Row Button
        const btnAddFr = document.getElementById("btnAddFindReplaceRow");
        if (btnAddFr && !btnAddFr.dataset.bound) {
            btnAddFr.dataset.bound = "true";
            btnAddFr.addEventListener("click", () => addFindReplaceRow());
        }

        // Toggle Schedule Options
        const radioNow = document.getElementById("radioNowRepost");
        const radioSched = document.getElementById("radioScheduleRepost");
        const schedBox = document.getElementById("repostScheduleBox");

        if (radioNow && radioSched && schedBox && !radioNow.dataset.bound) {
            radioNow.dataset.bound = "true";
            radioNow.addEventListener("change", () => schedBox.style.display = "none");
            radioSched.addEventListener("change", () => schedBox.style.display = "block");
        }
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initListeners);
    } else {
        initListeners();
    }

    return {
        openModal: openModal,
        scanPosts: scanPosts,
        startRepost: startRepost,
        addFindReplaceRow: addFindReplaceRow,
        getCheckedPages: getCheckedPages
    };
})();

window.openRepostOtherPagesModal = function () {
    const ctx = document.getElementById("contextMenu");
    if (ctx) ctx.style.display = "none";
    if (window.RepostOtherPages) window.RepostOtherPages.openModal();
};
