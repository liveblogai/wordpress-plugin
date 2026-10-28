import * as React from 'react'
import { useId } from 'react'

const outline =
  'M18.7224 21.1712C38.9467 4.38883 65.9125 2.15118 95.1254 7.74531C134.451 14.4583 179.393 30.1218 209.73 53.6172C228.831 68.1619 231.078 92.7761 218.719 112.915C205.236 135.292 178.27 153.193 153.551 171.094L95.1254 211.372C71.5303 228.154 41.1938 221.441 28.8345 199.065C13.1045 169.975 7.48662 133.054 2.99232 97.2514C-1.50198 62.5678 0.745172 36.8348 18.7224 21.1712Z'

// 保留提供的 Group.svg 的几何与颜色，每次显示独立分配渐变和裁切 ID。
export function LiveblogIcon() {
  const id = useId().replace(/:/g, '')
  const gradient = `liveblogai-gradient-${id}`
  const highlight = `liveblogai-highlight-${id}`
  const clip = `liveblogai-clip-${id}`

  return (
    <svg
      width="24"
      height="24"
      viewBox="0 0 226 226"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden="true"
      focusable="false"
    >
      <g clipPath={`url(#${clip})`}>
        <path d={outline} fill={`url(#${gradient})`} />
        <path d={outline} fill={`url(#${highlight})`} />
        {/* 线条单独禁止填充，避免继承 WordPress 对 SVG 设置的 currentColor。 */}
        <path
          d="M73.7776 62.5679V96.1327"
          fill="none"
          stroke="#2A0F14"
          strokeWidth="9.375"
          strokeLinecap="round"
        />
        <path
          d="M111.979 51.3795V96.1326"
          fill="none"
          stroke="#2A0F14"
          strokeWidth="9.375"
          strokeLinecap="round"
        />
        <path
          d="M41.1938 112.915C55.8003 137.529 85.0132 149.836 115.35 147.599C138.945 146.48 158.046 138.648 172.652 126.341"
          fill="none"
          stroke="#2A0F14"
          strokeWidth="10.1562"
          strokeLinecap="round"
        />
      </g>
      <defs>
        <linearGradient
          id={gradient}
          x1="27.7109"
          y1="22.2901"
          x2="191.039"
          y2="190.805"
          gradientUnits="userSpaceOnUse"
        >
          <stop stopColor="#E6BF24" />
          <stop offset="0.24" stopColor="#FAE261" />
          <stop offset="0.5" stopColor="#FC7EC7" />
          <stop offset="0.74" stopColor="#B3A5F5" />
          <stop offset="1" stopColor="#A6D4E7" />
        </linearGradient>
        <linearGradient
          id={highlight}
          x1="45.6881"
          y1="30.1218"
          x2="167.545"
          y2="177.196"
          gradientUnits="userSpaceOnUse"
        >
          <stop stopColor="white" stopOpacity="0.18" />
          <stop offset="1" stopColor="white" stopOpacity="0" />
        </linearGradient>
        <clipPath id={clip}>
          <rect width="226" height="226" fill="white" />
        </clipPath>
      </defs>
    </svg>
  )
}
