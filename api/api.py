import re
import httpx
import uvicorn
from fastapi import FastAPI, Query, HTTPException, Request
from fastapi.responses import JSONResponse

app = FastAPI(title="TikTok API Server Lite")

# Default headers to mimic official TikTok App request
TIKTOK_HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.212 Safari/537.36",
    "Referer": "https://www.tiktok.com/",
    "Cookie": "CykaBlyat=XD"
}

async def resolve_url(url: str) -> str:
    """Resolve short URLs and redirects to get the final TikTok URL."""
    async with httpx.AsyncClient(follow_redirects=True, timeout=3.0) as client:
        # Perform a HEAD request to quickly follow redirects without downloading the body
        response = await client.head(url, headers={"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"})
        return str(response.url)

def extract_video_id(url: str) -> str:
    """Extract TikTok Video ID from URL."""
    # Match video/1234567890
    match = re.search(r"video/(\d+)", url)
    if match:
        return match.group(1)
    
    # Match v=1234567890 (sometimes found in alternative URLs)
    match = re.search(r"v=(\d+)", url)
    if match:
        return match.group(1)
        
    return ""

async def fetch_tiktok_data(video_id: str) -> dict:
    """Fetch video metadata from TikTok's SG API endpoint (fast and stable from Asia)."""
    # Use SG api22-normal-c-alisg domain (much faster than US domains for Asian VPS)
    api_url = (
        f"https://api22-normal-c-alisg.tiktokv.com/aweme/v1/feed/?"
        f"aweme_id={video_id}&"
        f"iid=7318518857994389254&"
        f"device_id=7318517321748022790&"
        f"channel=googleplay&"
        f"app_name=musical_ly&"
        f"version_code=300904&"
        f"device_platform=android&"
        f"device_type=SM-ASUS_Z01QD&"
        f"os_version=9"
    )
    
    try:
        async with httpx.AsyncClient(timeout=3.0) as client:
            response = await client.get(api_url, headers=TIKTOK_HEADERS)
            if response.status_code != 200:
                raise Exception(f"TikTok API responded with status {response.status_code}")
            
            data = response.json()
            aweme_list = data.get("aweme_list", [])
            if not aweme_list:
                raise Exception("Video details not found in TikTok response")
                
            return aweme_list[0]
            
    except Exception as e:
        # Fallback logic using the free public TikWM API
        fallback_url = f"https://www.tikwm.com/api/?url=https://www.tiktok.com/video/{video_id}"
        try:
            async with httpx.AsyncClient(timeout=5.0) as client:
                fb_resp = await client.get(fallback_url)
                if fb_resp.status_code == 200:
                    fb_json = fb_resp.json()
                    fb_data = fb_json.get("data")
                    if fb_json.get("code") == 0 and fb_data:
                        # Map TikWM response structure to match expected aweme details
                        return {
                            "is_fallback": True,
                            "desc": fb_data.get("title", ""),
                            "create_time": fb_data.get("create_time", 0),
                            "author": {
                                "unique_id": fb_data.get("author", {}).get("unique_id", ""),
                                "nickname": fb_data.get("author", {}).get("nickname", ""),
                                "uid": str(fb_data.get("author", {}).get("id", ""))
                            },
                            "statistics": {
                                "comment_count": fb_data.get("comment_count", 0),
                                "digg_count": fb_data.get("digg_count", 0),
                                "play_count": fb_data.get("play_count", 0),
                                "share_count": fb_data.get("share_count", 0)
                            },
                            "video": {
                                "cover": {
                                    "url_list": [fb_data.get("cover", "")]
                                },
                                "play_addr": {
                                    "url_list": [fb_data.get("play", "")]
                                }
                            }
                        }
        except Exception as fb_err:
            pass
            
        # Re-raise error if both main API and fallback fail
        raise HTTPException(status_code=400, detail=f"Failed to fetch TikTok video. Error: {str(e)}")

@app.get("/api/hybrid/video_data")
async def get_video_data(request: Request, url: str = Query(..., description="TikTok or Douyin Video URL")):
    try:
        # 1. Resolve redirect if it's a short URL
        resolved_url = url
        if "vt.tiktok" in url or "vm.tiktok" in url or "v.douyin" in url or "/t/" in url:
            resolved_url = await resolve_url(url)
            
        # 2. Extract video ID
        video_id = extract_video_id(resolved_url)
        if not video_id:
            raise HTTPException(status_code=400, detail="Could not extract video ID from URL")
            
        # 3. Fetch from TikTok API
        aweme_data = await fetch_tiktok_data(video_id)
        
        # 4. Map the data to the format actions/video.php expects
        author_info = aweme_data.get("author", {})
        stats_info = aweme_data.get("statistics", {})
        video_info = aweme_data.get("video", {})
        
        # Get no-watermark video URL
        nwm_url = None
        play_addr = video_info.get("play_addr", {})
        if play_addr and play_addr.get("url_list"):
            nwm_url = play_addr["url_list"][0]
        if not nwm_url:
            download_addr = video_info.get("download_addr", {})
            if download_addr and download_addr.get("url_list"):
                nwm_url = download_addr["url_list"][0]
                
        # Get cover
        cover_url = ""
        cover_obj = video_info.get("cover", {})
        if cover_obj and cover_obj.get("url_list"):
            cover_url = cover_obj["url_list"][0]
            
        # Structure payload to match both Douyin_TikTok_Download_API structure
        mapped_data = {
            "source": "tikwm" if aweme_data.get("is_fallback") else "main_api",
            "type": "video",
            "platform": "tiktok",
            "id": video_id,
            "video_id": video_id,
            "desc": aweme_data.get("desc", ""),
            "create_time": aweme_data.get("create_time", 0),
            "author": {
                "unique_id": author_info.get("unique_id", ""),
                "nickname": author_info.get("nickname", ""),
                "id": str(author_info.get("uid", ""))
            },
            "statistics": {
                "comment_count": stats_info.get("comment_count", 0),
                "digg_count": stats_info.get("digg_count", 0),
                "play_count": stats_info.get("play_count", 0),
                "share_count": stats_info.get("share_count", 0)
            },
            "cover_data": {
                "cover": cover_url
            },
            "video_data": {
                "nwm_video_url": nwm_url,
                "nwm_video_url_HQ": nwm_url,
                "wm_video_url": nwm_url
            }
        }
        
        return {
            "code": 200,
            "router": request.url.path,
            "data": mapped_data
        }
        
    except HTTPException as he:
        return JSONResponse(status_code=he.status_code, content={"code": he.status_code, "msg": he.detail})
    except Exception as e:
        return JSONResponse(status_code=500, content={"code": 500, "msg": str(e)})

if __name__ == "__main__":
    uvicorn.run("api:app", host="127.0.0.1", port=8000, reload=False)
