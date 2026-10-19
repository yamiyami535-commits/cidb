from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    max_file_size_mb: int = 8

    # More flexible image quality requirements
    min_image_width: int = 250
    min_image_height: int = 150
    min_blur_score: float = 25.0
    min_brightness: float = 25.0
    max_brightness: float = 245.0

    # OCR and comparison settings
    min_ocr_confidence: float = 0.55
    min_name_similarity: float = 75.0
    manual_review_name_score: float = 60.0

    # CORS settings
    allowed_origins: str = (
        "http://localhost:5173,"
        "http://localhost:3000,"
        "http://localhost:8000,"
        "http://127.0.0.1:8001"
    )

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    @property
    def max_file_size_bytes(self) -> int:
        return self.max_file_size_mb * 1024 * 1024

    @property
    def cors_origins(self) -> list[str]:
        return [
            origin.strip()
            for origin in self.allowed_origins.split(",")
            if origin.strip()
        ]


settings = Settings()