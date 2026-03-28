import React from "react";
import Cropper from "react-easy-crop";
import { createPortal } from "react-dom";
import { useTranslation } from "react-i18next";
import "react-easy-crop/react-easy-crop.css";

function extractUploadError(error, fallback) {
  if (error?.response?.data?.message) {
    return String(error.response.data.message);
  }

  const validation = error?.response?.data?.errors;
  if (validation && typeof validation === "object") {
    const firstKey = Object.keys(validation)[0];
    const firstValue = validation[firstKey];
    if (Array.isArray(firstValue) && firstValue[0]) {
      return String(firstValue[0]);
    }
  }

  if (error instanceof Error && error.message) {
    return error.message;
  }

  return fallback;
}

function loadImageDimensions(file) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const image = new Image();
    image.onload = () => {
      resolve({
        width: image.naturalWidth,
        height: image.naturalHeight,
        url,
      });
    };
    image.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error("Unable to read selected image."));
    };
    image.src = url;
  });
}

function createCroppedPreviewUrl(file, crop) {
  return new Promise((resolve, reject) => {
    const sourceUrl = URL.createObjectURL(file);
    const image = new Image();
    image.onload = () => {
      const previewSize = 320;
      const canvas = document.createElement("canvas");
      canvas.width = previewSize;
      canvas.height = previewSize;

      const context = canvas.getContext("2d");
      if (!context) {
        URL.revokeObjectURL(sourceUrl);
        reject(new Error("Unable to create preview context."));
        return;
      }

      context.drawImage(
        image,
        Math.max(0, Math.round(crop.x)),
        Math.max(0, Math.round(crop.y)),
        Math.max(1, Math.round(crop.width)),
        Math.max(1, Math.round(crop.height)),
        0,
        0,
        previewSize,
        previewSize,
      );

      canvas.toBlob(
        (blob) => {
          URL.revokeObjectURL(sourceUrl);
          if (!blob) {
            reject(new Error("Unable to generate cropped preview."));
            return;
          }

          resolve(URL.createObjectURL(blob));
        },
        "image/jpeg",
        0.9,
      );
    };

    image.onerror = () => {
      URL.revokeObjectURL(sourceUrl);
      reject(new Error("Unable to generate cropped preview."));
    };

    image.src = sourceUrl;
  });
}

export default function ContactEditorPhotoSection({
  photo,
  photoUploadToken,
  photoRemove,
  constraints,
  submitting,
  onStagePhotoUpload,
  onRemovePhoto,
  onUndoPhotoRemoval,
  onClearPendingUpload,
}) {
  const { t } = useTranslation("contacts");
  const fileInputRef = React.useRef(null);
  const [modalOpen, setModalOpen] = React.useState(false);
  const [selectedFile, setSelectedFile] = React.useState(null);
  const [cropSourceUrl, setCropSourceUrl] = React.useState("");
  const [crop, setCrop] = React.useState({ x: 0, y: 0 });
  const [zoom, setZoom] = React.useState(1);
  const [croppedAreaPixels, setCroppedAreaPixels] = React.useState(null);
  const [pendingPreviewUrl, setPendingPreviewUrl] = React.useState("");
  const [uploading, setUploading] = React.useState(false);
  const [error, setError] = React.useState("");

  const allowedMimes = Array.isArray(constraints?.allowed_mimes)
    ? constraints.allowed_mimes
    : ["image/jpeg", "image/png", "image/webp"];
  const maxUploadBytes = Math.max(
    1,
    Math.round(Number(constraints?.max_upload_kb ?? 8192) * 1024),
  );
  const minCropSize = Math.max(1, Math.round(Number(constraints?.min_crop_size ?? 600)));

  const clearCropSelection = React.useCallback(() => {
    if (cropSourceUrl) {
      URL.revokeObjectURL(cropSourceUrl);
    }

    setModalOpen(false);
    setSelectedFile(null);
    setCropSourceUrl("");
    setCrop({ x: 0, y: 0 });
    setZoom(1);
    setCroppedAreaPixels(null);
  }, [cropSourceUrl]);

  const setPendingPreview = React.useCallback((nextUrl) => {
    setPendingPreviewUrl((previous) => {
      if (previous && previous !== nextUrl) {
        URL.revokeObjectURL(previous);
      }

      return nextUrl;
    });
  }, []);

  React.useEffect(
    () => () => {
      if (pendingPreviewUrl) {
        URL.revokeObjectURL(pendingPreviewUrl);
      }

      if (cropSourceUrl) {
        URL.revokeObjectURL(cropSourceUrl);
      }
    },
    [cropSourceUrl, pendingPreviewUrl],
  );

  React.useEffect(() => {
    if (photoUploadToken) {
      return;
    }

    setPendingPreview("");
  }, [photoUploadToken, setPendingPreview]);

  React.useEffect(() => {
    if (!modalOpen || typeof document === "undefined") {
      return undefined;
    }

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    const handleEscape = (event) => {
      if (event.key === "Escape" && !uploading) {
        clearCropSelection();
      }
    };

    window.addEventListener("keydown", handleEscape);

    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener("keydown", handleEscape);
    };
  }, [clearCropSelection, modalOpen, uploading]);

  const handleChooseFile = async (event) => {
    const file = event.target?.files?.[0];
    if (!file) {
      return;
    }

    setError("");

    if (!allowedMimes.includes(String(file.type).toLowerCase())) {
      setError(
        t("editor.photo.errors.format", {
          formats: allowedMimes.join(", "),
        }),
      );
      event.target.value = "";
      return;
    }

    if (file.size > maxUploadBytes) {
      setError(
        t("editor.photo.errors.maxSize", {
          sizeMb: (maxUploadBytes / (1024 * 1024)).toFixed(1),
        }),
      );
      event.target.value = "";
      return;
    }

    try {
      const dimensions = await loadImageDimensions(file);
      if (dimensions.width < minCropSize || dimensions.height < minCropSize) {
        URL.revokeObjectURL(dimensions.url);
        setError(
          t("editor.photo.errors.minDimensions", {
            min: minCropSize,
          }),
        );
        event.target.value = "";
        return;
      }

      clearCropSelection();
      setSelectedFile(file);
      setCropSourceUrl(dimensions.url);
      setModalOpen(true);
      setCrop({ x: 0, y: 0 });
      setZoom(1);
      setCroppedAreaPixels(null);
    } catch (err) {
      setError(extractUploadError(err, t("editor.photo.errors.read")));
    } finally {
      event.target.value = "";
    }
  };

  const handleSaveCrop = async () => {
    if (!selectedFile || !croppedAreaPixels) {
      return;
    }

    if (
      croppedAreaPixels.width < minCropSize ||
      croppedAreaPixels.height < minCropSize
    ) {
      setError(
        t("editor.photo.errors.minCrop", {
          min: minCropSize,
        }),
      );
      return;
    }

    setUploading(true);
    setError("");

    try {
      await onStagePhotoUpload({
        file: selectedFile,
        crop: croppedAreaPixels,
      });

      const previewUrl = await createCroppedPreviewUrl(selectedFile, croppedAreaPixels);
      setPendingPreview(previewUrl);
      clearCropSelection();
    } catch (err) {
      setError(extractUploadError(err, t("editor.photo.errors.upload")));
    } finally {
      setUploading(false);
    }
  };

  const displayUrl = photoRemove
    ? ""
    : pendingPreviewUrl || String(photo?.url ?? "").trim();
  const hasPersistedPhoto =
    photo && typeof photo.url === "string" && photo.url.trim() !== "";
  const hasPendingPhoto = !!photoUploadToken;
  const canRemove = hasPersistedPhoto || hasPendingPhoto;
  const photoStatus = photoRemove
    ? t("editor.photo.status.pendingRemoval")
    : hasPendingPhoto
      ? t("editor.photo.status.pendingUpload")
      : hasPersistedPhoto
        ? t("editor.photo.status.saved")
        : t("editor.photo.status.none");

  return (
    <section className="rounded-2xl border border-app-edge bg-app-panel/40 p-4">
      <div className="flex flex-wrap items-center gap-4">
        <div className="h-24 w-24 overflow-hidden rounded-2xl border border-app-edge bg-app-surface">
          {displayUrl ? (
            <img
              src={displayUrl}
              alt={t("editor.photo.previewAlt")}
              className="h-full w-full object-cover"
            />
          ) : (
            <div className="flex h-full w-full items-center justify-center text-xs font-semibold text-app-muted">
              {t("editor.photo.empty")}
            </div>
          )}
        </div>

        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-app-strong">{t("editor.photo.label")}</p>
          <p className="mt-1 text-xs text-app-muted">{t("editor.photo.description")}</p>
          <p className="mt-1 text-xs font-medium text-app-muted">{photoStatus}</p>
          <p className="mt-1 text-[11px] text-app-muted">
            {t("editor.photo.constraints", {
              min: minCropSize,
              maxMb: (maxUploadBytes / (1024 * 1024)).toFixed(1),
            })}
          </p>

          <div className="mt-3 flex flex-wrap gap-2">
            <input
              ref={fileInputRef}
              type="file"
              className="hidden"
              accept={allowedMimes.join(",")}
              onChange={handleChooseFile}
              disabled={submitting || uploading}
            />
            <button
              type="button"
              className="btn-outline btn-outline-sm"
              onClick={() => fileInputRef.current?.click()}
              disabled={submitting || uploading}
            >
              {displayUrl
                ? t("editor.photo.actions.change")
                : t("editor.photo.actions.upload")}
            </button>

            {photoRemove ? (
              <button
                type="button"
                className="btn-outline btn-outline-sm"
                onClick={onUndoPhotoRemoval}
                disabled={submitting || uploading}
              >
                {t("editor.photo.actions.undoRemove")}
              </button>
            ) : null}

            {!photoRemove && canRemove ? (
              <button
                type="button"
                className="btn-outline btn-outline-sm text-app-danger"
                onClick={() => {
                  onRemovePhoto();
                  if (pendingPreviewUrl) {
                    setPendingPreview("");
                  }
                }}
                disabled={submitting || uploading}
              >
                {t("editor.photo.actions.remove")}
              </button>
            ) : null}

            {!photoRemove && hasPendingPhoto && !hasPersistedPhoto ? (
              <button
                type="button"
                className="btn-outline btn-outline-sm"
                onClick={() => {
                  onClearPendingUpload();
                  if (pendingPreviewUrl) {
                    setPendingPreview("");
                  }
                }}
                disabled={submitting || uploading}
              >
                {t("editor.photo.actions.clearPending")}
              </button>
            ) : null}
          </div>
        </div>
      </div>

      {error ? (
        <p className="mt-3 rounded-xl border border-app-danger/30 bg-app-danger/10 px-3 py-2 text-xs text-app-danger">
          {error}
        </p>
      ) : null}

      {modalOpen && typeof document !== "undefined"
        ? createPortal(
            <div className="fixed inset-0 z-[120]">
              <button
                type="button"
                aria-label={t("editor.photo.actions.cancel")}
                className="absolute inset-0 bg-app-surface/70 backdrop-blur-[2px]"
                onClick={clearCropSelection}
                disabled={uploading}
              />

              <div className="relative mx-auto flex min-h-full w-full max-w-5xl items-start justify-center px-4 pb-6 pt-12 sm:pt-16">
                <div className="surface w-full max-w-3xl rounded-2xl border border-app-edge p-4 shadow-2xl shadow-black/10">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <h3 className="text-lg font-semibold text-app-strong">
                        {t("editor.photo.cropTitle")}
                      </h3>
                      <p className="mt-1 text-xs text-app-muted">
                        {t("editor.photo.cropDescription", {
                          min: minCropSize,
                        })}
                      </p>
                    </div>
                    <button
                      type="button"
                      className="btn-outline btn-outline-sm"
                      onClick={clearCropSelection}
                      disabled={uploading}
                    >
                      {t("editor.photo.actions.cancel")}
                    </button>
                  </div>

                  <div className="relative mt-4 h-[58vh] min-h-[280px] w-full overflow-hidden rounded-xl bg-black">
                    <Cropper
                      image={cropSourceUrl}
                      crop={crop}
                      zoom={zoom}
                      aspect={1}
                      onCropChange={setCrop}
                      onZoomChange={setZoom}
                      onCropComplete={(_, areaPixels) => setCroppedAreaPixels(areaPixels)}
                    />
                  </div>

                  <div className="mt-4">
                    <label
                      className="mb-1 block text-xs font-semibold text-app-muted"
                      htmlFor="photo-crop-zoom"
                    >
                      {t("editor.photo.zoomLabel")}
                    </label>
                    <input
                      id="photo-crop-zoom"
                      type="range"
                      min={1}
                      max={3}
                      step={0.01}
                      value={zoom}
                      onChange={(event) => setZoom(Number(event.target.value))}
                      className="w-full"
                      disabled={uploading}
                    />
                  </div>

                  <div className="mt-4 flex justify-end gap-2">
                    <button
                      type="button"
                      className="btn-outline btn-outline-sm"
                      onClick={clearCropSelection}
                      disabled={uploading}
                    >
                      {t("editor.photo.actions.cancel")}
                    </button>
                    <button
                      type="button"
                      className="btn"
                      onClick={handleSaveCrop}
                      disabled={uploading}
                    >
                      {uploading
                        ? t("editor.photo.actions.uploading")
                        : t("editor.photo.actions.useCrop")}
                    </button>
                  </div>
                </div>
              </div>
            </div>,
            document.body,
          )
        : null}

    </section>
  );
}
