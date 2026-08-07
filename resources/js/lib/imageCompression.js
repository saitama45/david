/**
 * Client-side image downscaling / compression.
 *
 * Phones produce 8-15MB photos that blow past the 5MB server limit, so we
 * re-encode them in the browser before they ever reach the request. The output
 * is always JPEG (quality is controllable, PNG's is not) drawn on a white
 * background so flattened transparency does not turn black.
 */

const DEFAULTS = {
  maxSizeBytes: 5 * 1024 * 1024,
  maxDimension: 2560, // longest edge after the first downscale
  minDimension: 800, // never shrink the longest edge below this
  qualitySteps: [0.85, 0.75, 0.65, 0.55, 0.45],
  mimeType: 'image/jpeg',
}

export const formatBytes = (bytes) => {
  if (!bytes && bytes !== 0) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

const loadBitmap = async (file) => {
  // createImageBitmap honours EXIF orientation, which canvas.drawImage does not.
  if (typeof createImageBitmap === 'function') {
    try {
      return await createImageBitmap(file, { imageOrientation: 'from-image' })
    } catch (e) {
      // Older browsers reject the options bag - fall through to the <img> path.
    }
  }

  return await new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file)
    const img = new Image()
    img.onload = () => {
      URL.revokeObjectURL(url)
      resolve(img)
    }
    img.onerror = () => {
      URL.revokeObjectURL(url)
      reject(new Error('Could not read the image file.'))
    }
    img.src = url
  })
}

const canvasToBlob = (canvas, mimeType, quality) =>
  new Promise((resolve) => canvas.toBlob(resolve, mimeType, quality))

const renderAtScale = (source, width, height) => {
  const canvas = document.createElement('canvas')
  canvas.width = Math.max(1, Math.round(width))
  canvas.height = Math.max(1, Math.round(height))

  const ctx = canvas.getContext('2d')
  ctx.fillStyle = '#FFFFFF' // flatten any transparency instead of leaving it black
  ctx.fillRect(0, 0, canvas.width, canvas.height)
  ctx.imageSmoothingEnabled = true
  ctx.imageSmoothingQuality = 'high'
  ctx.drawImage(source, 0, 0, canvas.width, canvas.height)

  return canvas
}

const withJpegExtension = (name) => {
  const base = name.replace(/\.[^.]+$/, '') || 'image'
  return `${base}.jpg`
}

/**
 * Shrink a file until it fits under the size limit.
 *
 * Returns { file, compressed, originalSize, size }. `file` is the original
 * object when it already fits and is within the dimension cap. Throws only if
 * the image cannot be decoded at all.
 */
export const compressImageFile = async (file, options = {}) => {
  const opts = { ...DEFAULTS, ...options }
  const originalSize = file.size

  const bitmap = await loadBitmap(file)
  const naturalWidth = bitmap.width || bitmap.naturalWidth
  const naturalHeight = bitmap.height || bitmap.naturalHeight

  if (!naturalWidth || !naturalHeight) {
    throw new Error('Could not read the image dimensions.')
  }

  const longestEdge = Math.max(naturalWidth, naturalHeight)

  // Nothing to do: already small enough and not absurdly large.
  if (originalSize <= opts.maxSizeBytes && longestEdge <= opts.maxDimension) {
    bitmap.close?.()
    return { file, compressed: false, originalSize, size: originalSize }
  }

  let scale = longestEdge > opts.maxDimension ? opts.maxDimension / longestEdge : 1
  let best = null

  try {
    while (true) {
      const canvas = renderAtScale(bitmap, naturalWidth * scale, naturalHeight * scale)

      for (const quality of opts.qualitySteps) {
        const blob = await canvasToBlob(canvas, opts.mimeType, quality)
        if (!blob) continue

        if (!best || blob.size < best.size) {
          best = blob
        }

        if (blob.size <= opts.maxSizeBytes) {
          best = blob
          break
        }
      }

      if (best && best.size <= opts.maxSizeBytes) break

      // Still too big at the lowest quality - drop the resolution and retry.
      const nextScale = scale * 0.75
      if (Math.max(naturalWidth, naturalHeight) * nextScale < opts.minDimension) break
      scale = nextScale
    }
  } finally {
    bitmap.close?.()
  }

  if (!best) {
    throw new Error('Could not compress the image.')
  }

  // If re-encoding somehow made things worse, keep the original.
  if (best.size >= originalSize && originalSize <= opts.maxSizeBytes) {
    return { file, compressed: false, originalSize, size: originalSize }
  }

  const compressedFile = new File([best], withJpegExtension(file.name), {
    type: opts.mimeType,
    lastModified: Date.now(),
  })

  return {
    file: compressedFile,
    compressed: true,
    originalSize,
    size: compressedFile.size,
  }
}
